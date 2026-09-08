import os
import cv2
import numpy as np
import base64
import math
import re
from flask import Flask, request, jsonify
from ultralytics import YOLO
from paddleocr import PaddleOCR

app = Flask(__name__)

print("=========================================================")
print("Iniciando Servidor OCR (YOLO OBB + PaddleOCR)...")
print("=========================================================")

# 1. Carregar YOLO-OBB (com fallback caso não exista ainda)
model_path = 'trael-obb.pt'
if os.path.exists(model_path):
    print(f"Carregando modelo OBB: {model_path}")
    obb_model = YOLO(model_path)
else:
    print(f"AVISO: Modelo {model_path} não encontrado! O pipeline OBB será simulado ou ignorado até que você treine o modelo.")
    obb_model = None

# 2. Carregar PaddleOCR
print("Carregando PaddleOCR (isso pode demorar na primeira vez)...")
# use_angle_cls=True ajuda internamente o PaddleOCR, mas nós faremos a Dupla Orientação explicitamente.
ocr_model = PaddleOCR(use_angle_cls=True, lang='en', show_log=False)
print("Modelos carregados com sucesso!")

def decode_base64_image(base64_string):
    if ',' in base64_string:
        base64_string = base64_string.split(',')[1]
    img_data = base64.b64decode(base64_string)
    nparr = np.frombuffer(img_data, np.uint8)
    img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
    return img

def square_padding(image):
    """
    Pad the image to make it a square (max(width, height)) with black background.
    """
    h, w = image.shape[:2]
    size = max(h, w)
    padded = np.zeros((size, size, 3), dtype=np.uint8)
    # Center the image
    y_offset = (size - h) // 2
    x_offset = (size - w) // 2
    padded[y_offset:y_offset+h, x_offset:x_offset+w] = image
    return padded

def compute_iou(box1, box2):
    """
    box format: [x_min, y_min, x_max, y_max]
    """
    x1_inter = max(box1[0], box2[0])
    y1_inter = max(box1[1], box2[1])
    x2_inter = min(box1[2], box2[2])
    y2_inter = min(box1[3], box2[3])

    if x2_inter < x1_inter or y2_inter < y1_inter:
        return 0.0

    inter_area = (x2_inter - x1_inter) * (y2_inter - y1_inter)
    box1_area = (box1[2] - box1[0]) * (box1[3] - box1[1])
    box2_area = (box2[2] - box2[0]) * (box2[3] - box2[1])
    union_area = box1_area + box2_area - inter_area

    if union_area == 0:
        return 0.0
    return inter_area / union_area

def post_process_ocr(ocr_results):
    """
    Filtra sobreposições via IoU e extrai o texto com melhor confiança.
    ocr_results format from PaddleOCR:
    [ [ [[x1,y1],[x2,y2],[x3,y3],[x4,y4]], ('text', confidence) ], ... ]
    """
    if not ocr_results or not ocr_results[0]:
        return [], 0.0

    # Flatten the results if multiple lines
    boxes_info = []
    for line in ocr_results[0]:
        coords, (text, conf) = line

        # Obter bounding box axis-aligned (xmin, ymin, xmax, ymax)
        x_coords = [p[0] for p in coords]
        y_coords = [p[1] for p in coords]
        xmin, xmax = min(x_coords), max(x_coords)
        ymin, ymax = min(y_coords), max(y_coords)

        # Limpeza rápida
        text_clean = ''.join(e for e in text if e.isalnum() or e == '-')
        if len(text_clean) >= 1:
            boxes_info.append({
                'box': [xmin, ymin, xmax, ymax],
                'text': text_clean,
                'conf': conf
            })

    # IoU Overlap Removal
    boxes_to_keep = []
    for i, b1 in enumerate(boxes_info):
        keep = True
        for j, b2 in enumerate(boxes_info):
            if i != j:
                iou = compute_iou(b1['box'], b2['box'])
                if iou > 0.5:
                    # Se sobrepõe em mais de 50%, perde para quem tem mais confiança
                    if b1['conf'] < b2['conf']:
                        keep = False
                        break
        if keep:
            boxes_to_keep.append(b1)

    # Sorting from top to bottom, then left to right
    # (Simplified: sort by Y, then X)
    boxes_to_keep = sorted(boxes_to_keep, key=lambda b: (b['box'][1], b['box'][0]))

    final_texts = []
    total_conf = 0.0
    for b in boxes_to_keep:
        final_texts.append(b['text'])
        total_conf += b['conf']

    avg_conf = total_conf / len(final_texts) if final_texts else 0.0
    return final_texts, avg_conf


def apply_filters(image):
    """
    CLAHE (realce de contraste local) + TopHat (destaca sulcos/relevos pequenos)
    — usado no pipeline PaddleOCR pra puncionamento fraco (ver analisar_com_paddleocr()).
    """
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15))
    tophat = cv2.morphologyEx(enhanced, cv2.MORPH_TOPHAT, kernel)
    result = cv2.add(enhanced, tophat)
    return cv2.cvtColor(result, cv2.COLOR_GRAY2BGR)


def analisar_com_paddleocr(image_b64, vertical=False):
    """
    Pipeline completo YOLO-OBB + PaddleOCR (múltiplas orientações, cru+realçado).
    `vertical=True` também testa 90º/270º — usado pra campos pintados como
    coluna de dígitos empilhados (ex.: patrimônio do tanque), não como linha.
    Devolve (final_texts, orientation). Levanta exceção se a imagem não puder
    ser decodificada — quem chamar decide o que fazer (erro pro operador).
    """
    # 1. Decodificar imagem
    img = decode_base64_image(image_b64)
    if img is None:
        raise ValueError('Falha ao processar a imagem.')

    # 2. Executar OBB (se disponível) para extrair região e ângulo
    cropped_img = img
    if obb_model is not None:
        results = obb_model(img, conf=0.5)
        if len(results) > 0 and results[0].obb is not None and len(results[0].obb) > 0:
            obb_result = results[0].obb

            # Prioriza a classe de texto (TXT) quando o modelo distingue classes
            # (SQR/CIR/TXT); cai para "maior confiança entre todas" se o modelo
            # não tiver essa classe cadastrada ou não detectar nenhum TXT na cena.
            # Antes disso pegava sempre obb[0] (o primeiro detectado, não
            # necessariamente o certo nem o mais confiável).
            class_names = obb_model.names if hasattr(obb_model, 'names') else {}
            txt_class_id = None
            for cls_id, cls_name in class_names.items():
                if str(cls_name).upper() == 'TXT':
                    txt_class_id = cls_id
                    break

            cls_tensor = obb_result.cls.cpu().numpy()
            conf_tensor = obb_result.conf.cpu().numpy()

            candidate_indices = list(range(len(conf_tensor)))
            if txt_class_id is not None:
                txt_indices = [i for i in candidate_indices if int(cls_tensor[i]) == txt_class_id]
                if txt_indices:
                    candidate_indices = txt_indices

            best_idx = max(candidate_indices, key=lambda i: conf_tensor[i])
            best_obb = obb_result[best_idx]

            # Para ultralytics, obb coords can be fetched as xywhr
            # xywhr: [x_center, y_center, width, height, rotation_in_radians]
            xywhr = best_obb.xywhr[0].cpu().numpy()
            cx, cy, w, h, angle_rad = xywhr
            angle_deg = math.degrees(angle_rad)

            # Obter a matriz de rotação em 2D
            M = cv2.getRotationMatrix2D((cx, cy), angle_deg, 1.0)

            # Aplicar a transformação afim para rotacionar a imagem toda
            h_img, w_img = img.shape[:2]
            rotated_img = cv2.warpAffine(img, M, (w_img, h_img), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)

            # Agora recortar o bounding box (que agora está reto)
            # Como rodamos ao redor do centro (cx, cy), o crop é simples
            x1 = int(max(0, cx - w/2))
            y1 = int(max(0, cy - h/2))
            x2 = int(min(w_img, cx + w/2))
            y2 = int(min(h_img, cy + h/2))

            cropped_img = rotated_img[y1:y2, x1:x2]

            if cropped_img.size == 0:
                cropped_img = img # Fallback
        else:
            print("OBB: Nenhuma região detectada. Usando imagem original.")

    # 3. Square Padding
    padded_img = square_padding(cropped_img)

    # 4. Geração de orientações candidatas — 0º/180º sempre (puncionamento de
    # cabeça pra baixo é comum), e também 90º/270º quando `vertical=True`
    # (campos como o patrimônio do tanque, pintado como uma COLUNA de dígitos
    # empilhados, não como uma linha — girar 90º transforma essa coluna numa
    # linha horizontal normal, que o detector de texto do PaddleOCR já lê bem).
    ROTACOES_CV = {0: None, 90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}
    angulos = [0, 180] + ([90, 270] if vertical else [])

    # Combinar resultados para que o IoU Overlap Removal escolha a melhor confiança
    def combine_results(r1, r2):
        combined = []
        if r1 and r1[0]: combined.extend(r1[0])
        if r2 and r2[0]: combined.extend(r2[0])
        return [combined] if combined else []

    def invert_180_text(text_str):
        char_map = {
            '0': '0', '1': '1', '2': '5', '5': '2',
            '6': '9', '8': '8', '9': '6',
            'O': '0', 'o': '0', 'I': '1', 'l': '1',
            '-': '-', '.': '.', ' ': ' ',
            'X': 'X', 'x': 'x', 'H': 'H', 'N': 'N', 'Z': 'Z', 'S': 'S', 's': 's'
        }
        rev = ''.join(char_map.get(c, c) for c in reversed(text_str))
        return rev

    # 5/6. Inferência PaddleOCR (Multi-pass: Crua + Filtrada) por orientação,
    # com peso extra de confiança quando a formatação do NS (6 dígitos) aparece.
    resultados_por_angulo = {}
    for ang in angulos:
        rot = ROTACOES_CV[ang]
        img_ang = padded_img if rot is None else cv2.rotate(padded_img, rot)
        img_ang_filtered = apply_filters(img_ang)

        res_raw = ocr_model.ocr(img_ang, cls=True)
        res_filt = ocr_model.ocr(img_ang_filtered, cls=True)
        result = combine_results(res_raw, res_filt)

        texts, conf = post_process_ocr(result)
        has_sn = any(re.search(r'\b\d{6}\b', t) for t in texts)
        score = conf + (0.5 if has_sn else 0.0)
        resultados_por_angulo[ang] = (texts, score)
        print(f"Confiança {ang}º: {score:.2f} | Textos: {texts}")

    melhor_angulo = max(resultados_por_angulo, key=lambda a: resultados_por_angulo[a][1])
    candidate_pool = list(resultados_por_angulo[melhor_angulo][0])
    secondary_pool = []
    for ang, (texts, _score) in resultados_por_angulo.items():
        if ang != melhor_angulo:
            secondary_pool.extend(texts)

    # Adiciona inversões semânticas a 180º de todos os textos detectados
    for t in list(candidate_pool) + list(secondary_pool):
        inv = invert_180_text(t)
        if inv and inv not in candidate_pool:
            candidate_pool.append(inv)

    # Adiciona a lista secundária (das outras orientações testadas)
    for t in secondary_pool:
        if t not in candidate_pool:
            candidate_pool.append(t)

    # Remove duplicatas preservando a ordem
    final_texts = list(dict.fromkeys(candidate_pool))
    orientation = f'{melhor_angulo}_deg'
    return final_texts, orientation


@app.route('/analisar', methods=['POST'])
def analisar():
    data = request.json
    if not data or 'image' not in data:
        return jsonify({'error': 'Nenhuma imagem enviada.'}), 400

    # Dica opcional do PHP: esse campo é pintado como uma COLUNA de dígitos
    # empilhados na vertical (ex.: patrimônio do tanque), não como uma linha —
    # ativa também as orientações 90º/270º no PaddleOCR (mais lento, por isso
    # só liga quando o slot pede, ver api/paint-check-multi.php).
    vertical = bool(data.get('vertical'))

    try:
        final_texts, orientation = analisar_com_paddleocr(data['image'], vertical=vertical)
        return jsonify({
            'success': True,
            'textos_encontrados': final_texts,
            'orientation': orientation,
            'motor_usado': 'paddleocr',
        })
    except Exception as e:
        print("Erro na análise:", e)
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    # Roda o servidor localmente
    app.run(host='0.0.0.0', port=5000, debug=False)
