-- Migração: Restringe local 'GER' apenas para Revitalizações (R%)
-- Reprovas de linha/caldeiraria/tanque legadas com GER passam para IQF

UPDATE reprovas 
SET local = 'IQF' 
WHERE local = 'GER' AND codigo NOT LIKE 'R%';

UPDATE reprovas
SET local = 'GER', setor_causador = 'REVITALIZAÇÃO'
WHERE codigo LIKE 'R%';
