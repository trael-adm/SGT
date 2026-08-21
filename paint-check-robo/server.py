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
print("Iniciando Servidor OCR Híbrido (YOLO OBB + PaddleOCR)...")
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

@app.route('/analisar', methods=['POST'])
def analisar():
    data = request.json
    if not data or 'image' not in data:
        return jsonify({'error': 'Nenhuma imagem enviada.'}), 400
        
    try:
        # 1. Decodificar imagem
        img = decode_base64_image(data['image'])
        if img is None:
             return jsonify({'error': 'Falha ao processar a imagem.'}), 400
        
        # 2. Executar OBB (se disponível) para extrair região e ângulo
        cropped_img = img
        if obb_model is not None:
            results = obb_model(img, conf=0.5)
            if len(results) > 0 and results[0].obb is not None and len(results[0].obb) > 0:
                # Pegar o objeto com maior confiança
                best_obb = results[0].obb[0]
                
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
        
        # 4. Geração de Dupla Orientação (0 e 180 graus)
        img_0 = padded_img
        img_180 = cv2.rotate(padded_img, cv2.ROTATE_180)
        
        # Filtros matemáticos para realçar puncionamento (Gancho) sem prejudicar a serigrafia original
        def apply_filters(image):
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            # CLAHE para equalização de histograma
            clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
            enhanced = clahe.apply(gray)
            # TopHat para destacar pequenos detalhes brilhantes (sulcos)
            kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15))
            tophat = cv2.morphologyEx(enhanced, cv2.MORPH_TOPHAT, kernel)
            # Adicionar o TopHat à imagem equalizada
            result = cv2.add(enhanced, tophat)
            return cv2.cvtColor(result, cv2.COLOR_GRAY2BGR)
            
        img_0_filtered = apply_filters(img_0)
        img_180_filtered = apply_filters(img_180)
        
        # 5. Inferência PaddleOCR (Multi-pass: Crua + Filtrada)
        res_0_raw = ocr_model.ocr(img_0, cls=True)
        res_0_filt = ocr_model.ocr(img_0_filtered, cls=True)
        
        res_180_raw = ocr_model.ocr(img_180, cls=True)
        res_180_filt = ocr_model.ocr(img_180_filtered, cls=True)
        
        # Combinar resultados para que o IoU Overlap Removal escolha a melhor confiança
        def combine_results(r1, r2):
            combined = []
            if r1 and r1[0]: combined.extend(r1[0])
            if r2 and r2[0]: combined.extend(r2[0])
            return [combined] if combined else []
            
        result_0 = combine_results(res_0_raw, res_0_filt)
        result_180 = combine_results(res_180_raw, res_180_filt)
        
        # 6. Pós-Processamento e Seleção de Orientação
        texts_0, conf_0 = post_process_ocr(result_0)
        texts_180, conf_180 = post_process_ocr(result_180)
        
        # Regex flexível para o formato Trael:
        # Verifica se alguma string possui 6 números exatos.
        has_sn_0 = any(re.search(r'\b\d{6}\b', t) for t in texts_0)
        has_sn_180 = any(re.search(r'\b\d{6}\b', t) for t in texts_180)
        
        # Peso extra de confiança se a formatação foi encontrada
        score_0 = conf_0 + (0.5 if has_sn_0 else 0.0)
        score_180 = conf_180 + (0.5 if has_sn_180 else 0.0)
        
        print(f"Confiança 0º: {score_0:.2f} | Textos: {texts_0}")
        print(f"Confiança 180º: {score_180:.2f} | Textos: {texts_180}")
        
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

        # Coleta os textos da orientação vencedora e também da secundária + inversões
        candidate_pool = list(texts_0 if score_0 >= score_180 else texts_180)
        secondary_pool = list(texts_180 if score_0 >= score_180 else texts_0)
        
        # Adiciona inversões semânticas a 180º de todos os textos detectados
        for t in list(candidate_pool) + list(secondary_pool):
            inv = invert_180_text(t)
            if inv and inv not in candidate_pool:
                candidate_pool.append(inv)
        
        # Adiciona a lista secundária
        for t in secondary_pool:
            if t not in candidate_pool:
                candidate_pool.append(t)

        # Remove duplicatas preservando a ordem
        final_texts = list(dict.fromkeys(candidate_pool))
        
        return jsonify({
            'success': True,
            'textos_encontrados': final_texts,
            'orientation': '0_deg' if score_0 >= score_180 else '180_deg'
        })
        
    except Exception as e:
        print("Erro na análise:", e)
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    # Roda o servidor localmente
    app.run(host='0.0.0.0', port=5000, debug=False)
