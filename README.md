# NoteKit

O NoteKit é uma alternativa "single-file" minimalista ao Evernote, escrita em PHP puro e JavaScript "vanilla".

Este projeto foi modificado para incluir um sistema de autenticação (com utilizador na base de dados), auto-save, pré-visualização de Markdown e outras melhorias de segurança e usabilidade.

## Recursos

* **Organização:** Cadernos (Notebooks), Notas e Tags.
* **Editor:** Suporte a Markdown com pré-visualização.
* **Funcionalidades:** Fixar (pinned), Arquivar (soft delete) e Excluir.
* **Busca:** Pesquisa FULLTEXT (se disponível no MySQL) ou `LIKE`.
* **UI:** Interface SPA (Single Page Application) sem bibliotecas externas (exceto `marked.js` para Markdown).
* **Segurança:** Login por sessão (utilizador no BD) e proteção contra CSRF.
* **UX:** Auto-save (1.5s após digitar) e paginação.

## Instalação

Este projeto é um único ficheiro `index.php` que cria o seu próprio schema de base de dados, incluindo o utilizador inicial.

### 1. Base de Dados

Você precisa de um servidor MySQL.

1.  Crie uma base de dados (ex: `notekit`):
    ```sql
    CREATE DATABASE notekit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ```
2.  Crie um utilizador e dê-lhe permissões (substitua a senha!):
    ```sql
    CREATE USER 'notekit'@'localhost' IDENTIFIED BY 'SUA_SENHA_SEGURA_AQUI';
    GRANT ALL PRIVILEGES ON notekit.* TO 'notekit'@'localhost';
    FLUSH PRIVILEGES;
    ```

### 2. Configuração

1.  Abra o ficheiro `index.php`.
2.  Edite as constantes de configuração da base de dados no topo do ficheiro:
    ```php
    const DB_DSN  = 'mysql:host=localhost;dbname=notekit;charset=utf8mb4';
    const DB_USER = 'notekit';
    const DB_PASS = 'SUA_SENHA_SEGURA_AQUI'; // <- Mude isto
    ```

### 3. Execução

1.  Coloque o ficheiro `index.php` num servidor web com PHP (ex: Apache, Nginx).
2.  Aceda ao ficheiro no seu navegador.
3.  O `index.php` irá criar automaticamente todas as tabelas (`notes`, `notebooks`, `tags`, `users`) e irá inserir o utilizador padrão (`admin`) com a senha `admin123`.

## Login

As credenciais padrão são:

* **Utilizador:** `admin`
* **Senha:** `admin123`

### Como Mudar a Senha do Admin

Para mudar a senha, você precisa de gerar um novo hash de senha PHP e atualizar a base de dados.

1.  Execute este comando no seu terminal (ou use um gerador online):
    ```bash
    php -r "echo password_hash('sua-nova-senha-segura', PASSWORD_DEFAULT);"
    ```
2.  Copie o hash gerado (algo como `$2y$10$...`).
3.  Execute este comando SQL na sua base de dados `notekit`:
    ```sql
    UPDATE users SET password_hash = 'SEU_NOVO_HASH_AQUI' WHERE username = 'admin';
    ```
