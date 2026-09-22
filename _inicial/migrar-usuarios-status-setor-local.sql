-- ==============================================================================
-- MIGRAÇÃO: colunas novas em Usuários para a tela de Admin (Gestão de Usuários)
-- ------------------------------------------------------------------------------
-- Produção já rodou uma migração que RENOMEIA ativo→status e id_alocacao→id_setor
-- (ver dump de referência em _inicial/dump_completo_para_railway.sql), mas essa
-- renomeação nunca virou arquivo .sql e vários outros arquivos deste repo ainda
-- leem `usuarios.ativo`/`id_alocacao` diretamente — renomear aqui quebraria eles.
--
-- Por isso esta versão LOCAL é aditiva: cria status/id_setor/matricula/turno como
-- colunas NOVAS (sincronizadas a partir de ativo/id_alocacao), sem tocar nas
-- antigas. `login.php` já lê `status` com fallback pra `ativo`, e `id_alocacao`
-- com fallback pra `id_setor` — então os dois esquemas convivem.
--
-- Idempotente (usa information_schema pra só alterar o que ainda falta).
-- ==============================================================================

DELIMITER //
CREATE PROCEDURE _tmp_add_col_if_missing(
    IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_ddl VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', p_tabela, ' ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _tmp_add_col_if_missing('usuarios', 'matricula', 'matricula VARCHAR(30) NULL AFTER id');
CALL _tmp_add_col_if_missing('usuarios', 'turno', 'turno VARCHAR(20) NULL AFTER matricula');
CALL _tmp_add_col_if_missing('usuarios', 'id_setor', 'id_setor INT NULL AFTER id_alocacao');
CALL _tmp_add_col_if_missing('usuarios', 'status', "status VARCHAR(20) NOT NULL DEFAULT 'pendente' AFTER e_executor");

UPDATE usuarios SET id_setor = id_alocacao WHERE id_setor IS NULL AND id_alocacao IS NOT NULL;
UPDATE usuarios SET status = IF(ativo = 1, 'ativo', 'inativo') WHERE status = 'pendente';

-- FK id_setor -> setores(id), só se ainda não existir
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_NAME = 'fk_usuarios_setor'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_setor FOREIGN KEY (id_setor) REFERENCES setores(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE _tmp_add_col_if_missing;
