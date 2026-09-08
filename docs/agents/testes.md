# Testes automatizados: PHPUnit (dev-only)

O projeto não tinha nenhum teste automatizado até esta suíte ser criada. É PHPUnit via Composer, mas os dois ficam **só em dev** — `composer.json`, `composer.lock`, `composer.phar`, `vendor/`, `phpunit.xml` e `tests/` estão no `.dockerignore`, nunca sobem pra produção.

## Rodar os testes

```powershell
.\scripts\run-tests.ps1
```

O script acha sozinho o PHP portátil (mesmo protocolo de `gemini.md`: procura em `~/Downloads/php-*-Win32-*/php.exe`) e já carrega as extensões necessárias (`pdo_mysql`, `mbstring`). Não precisa de Composer nem PHP no PATH do sistema.

Se preferir rodar na mão (Bash), o comando equivalente é:
```bash
PHP="$HOME/Downloads/php-8.4.21-Win32-vs17-x64/php.exe"   # ajuste a versão
EXTDIR="$(dirname "$PHP")/ext"
"$PHP" -d extension_dir="$EXTDIR" -d extension=pdo_mysql -d extension=mbstring vendor/phpunit/phpunit/phpunit
```

## Como os testes são organizados

- `tests/bootstrap.php` — carrega `vendor/autoload.php` + os `config/`/`includes/` que os testes precisam (o projeto não usa autoload PSR-4, é `require_once` manual, igual o resto do app).
- `tests/TestCase.php` — classe base: abre uma transação no `setUp()` e sempre desfaz (`ROLLBACK`) no `tearDown()`. **Todo teste roda contra o banco de dev real** (`trael_db_dev`, mesma conexão de `getDB()`), nunca contra mocks — decisão deliberada, pra pegar bug de query/schema que um mock nunca pegaria (foi assim que achamos o bug do `local_codigo_adicional` de valor único). Nenhum teste deixa dado gravado de verdade, mesmo os que fazem INSERT/UPDATE.
- Um arquivo de teste por arquivo `includes/`, espelhando a estrutura (`tests/Includes/ConcessionariaRegrasTest.php` testa `includes/concessionaria-regras.php`).

## Limitação conhecida

O PHP portátil usado aqui não tem as extensões `tokenizer`/`dom`/`xml`/`xmlwriter` — os testes rodam normalmente sem elas, mas relatório de cobertura (`--coverage-html` etc.) do PHPUnit não funciona neste ambiente.

## O que ainda não é testável sem refactor

Funções de lógica de negócio definidas dentro de arquivos de `api/*.php` (ex.: `checkMatchCampo()` em `api/paint-check-multi.php`) não são importáveis pros testes hoje — o arquivo inteiro processa a requisição HTTP no nível principal (lê `php://input`, checa sessão, pode dar `exit`). Pra testar essa lógica é preciso primeiro extraí-la pra um arquivo `includes/` próprio, sem efeito colateral no include — ainda não feito, é o próximo passo natural depois desta base.
