<?php
/**
 * NoteKit — alternativa ao Evernote (PHP + MySQL + CSS + AJAX)
 * Single-file, completo e pronto para uso.
 * ------------------------------------------------------------
 * Recursos:
 * - Cadernos (notebooks), Notas, Tags, Busca (FULLTEXT se disponível)
 * - Fixar (pinned), Arquivar (soft delete), Excluir
 * - UI SPA com fetch() (sem libs), tema escuro focado
 * - [MOD] Autenticação (Sessão + BD) e proteção CSRF
 * - [MOD] Editor Rich Text (WYSIWYG) com TinyMCE
 * - [MOD] Suporte a Upload de Imagens e Anexos de Arquivo
 */

//------------------------------------------------------------
// CONFIGURAÇÃO
//------------------------------------------------------------
const DB_DSN  = 'mysql:host=localhost;dbname=notekit;charset=utf8mb4';
const DB_USER = 'notekit';
const DB_PASS = 'PL2VYNN0E5Hw4PGsOuTv';

// Diretório para salvar uploads de arquivos (DEVE TER PERMISSÃO DE ESCRITA)
const UPLOADS_DIR = 'uploads/';
// Limite máximo de arquivo (em bytes)
const MAX_FILE_SIZE = 10485760; // 10MB

// DEBUG: Em produção, mude para 'false' para ocultar detalhes de erros.
const DEBUG = true;

//------------------------------------------------------------
// BOOTSTRAP E ROTEAMENTO
//------------------------------------------------------------

session_start();
$is_logged_in = isset($_SESSION['user']);
$action = $_GET['api'] ?? null;

// Roteamento API JSON (AJAX)
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Inicializa o PDO
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        json(['error' => DEBUG ? 'Erro de conexão com o BD: ' . $e->getMessage() : 'Erro de conexão com o BD.']);
        exit;
    }

    // Endpoints públicos (login)
    if ($action === 'login') {
        try {
            ensure_schema($pdo); // Garante que a tabela users exista antes do login
            json(handle_login($pdo, read_json()));
        } catch (Throwable $e) {
            http_response_code(401);
            json(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    // Proteção de API (requer login)
    if (!$is_logged_in) {
        http_response_code(401);
        json(['error' => 'Não autenticado']);
        exit;
    }

    // Proteção CSRF para ações POST (excluindo upload de arquivos)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'uploadFile') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            json(['error' => 'Token CSRF inválido']);
            exit;
        }
    }

    // Endpoints protegidos
    try {
        // Migração só é necessária em chamadas que usam o $pdo
        ensure_schema($pdo); 

        switch ($action) {
            case 'listNotes':       json(list_notes($pdo)); break;
            case 'getNote':         json(get_note($pdo, require_int('id'))); break;
            case 'saveNote':        json(save_note($pdo, read_json())); break;
            case 'deleteNote':      json(delete_note($pdo, require_int('id'))); break;
            case 'archiveNote':     json(archive_note($pdo, require_int('id'), require_bool('archived'))); break;
            case 'pinNote':         json(pin_note($pdo, require_int('id'), require_bool('pinned'))); break;
            case 'listTags':        json(list_tags($pdo)); break;
            case 'listNotebooks':   json(list_notebooks($pdo)); break;
            case 'createNotebook':  json(create_notebook($pdo, require_str('name'))); break;
            case 'renameNotebook':  json(rename_notebook($pdo, require_int('id'), require_str('name'))); break;
            case 'deleteNotebook':  json(delete_notebook($pdo, require_int('id'))); break;
            
            // Novos Endpoints para Rich Text e Anexos
            case 'uploadFile':      json(handle_upload_file($pdo)); break;
            case 'listAttachments': json(list_attachments($pdo, require_int('note_id'))); break;
            case 'deleteAttachment':json(delete_attachment($pdo, require_int('id'))); break;

            default: http_response_code(404); echo json_encode(['error' => 'API desconhecida']);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        // Em modo DEBUG=false, não vaza a mensagem de erro
        echo json_encode(['error' => DEBUG ? $e->getMessage() : 'Ocorreu um erro interno.']);
    }
    exit;
}

// Se não for API e não estiver logado, mostra o formulário de login
if (!$is_logged_in):
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />
<title>NoteKit — Login</title>
<style>
    :root { --bg:#0b0f13; --panel:#141a22; --text:#e8edf2; --border:#1c232c; --accent:#3a9fff; --ink:#0e141b; --danger:#ef4444; }
    html,body { height:100%; margin:0; background:var(--bg); color:var(--text); font:15px/1.5 "Inter",system-ui,sans-serif; display:grid; place-items:center; }
    form { background:var(--panel); border:1px solid var(--border); border-radius:16px; padding:24px; width:300px; box-shadow:0 8px 24px rgba(0,0,0,0.3); }
    .input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--ink); color:var(--text); box-sizing:border-box; margin-bottom:12px; transition: border-color .2s; }
    .input:focus { outline: none; border-color: var(--accent); }
    .btn { cursor:pointer; border:none; border-radius:10px; padding:10px 12px; background:var(--accent); color:#fff; width:100%; font-weight:600; transition: background .2s, transform .1s; }
    .btn:hover { background: #2f8eee; }
    .btn:active { transform: translateY(1px); }
    #error { color:var(--danger); text-align:center; margin-top:10px; font-size:14px; }
</style>
</head>
<body>
    <form id="loginForm">
        <h2 style="text-align:center;margin-top:0">NoteKit Login</h2>
        <input id="user" class="input" placeholder="Utilizador" value="admin" required />
        <input id="pass" type="password" class="input" placeholder="Senha" value="admin123" required />
        <button class="btn" type="submit">Entrar</button>
        <div id="error"></div>
    </form>
<script>
document.getElementById('loginForm').onsubmit = async (e) => {
    e.preventDefault();
    const err = document.getElementById('error');
    err.textContent = '';
    try {
        const res = await fetch('?api=login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                user: document.getElementById('user').value,
                pass: document.getElementById('pass').value
            })
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        if (data.ok) location.reload();
    } catch (e) {
        err.textContent = e.message;
    }
};
</script>
</body>
</html>
<?php
exit; // Fim do script se não estiver logado
endif;

// --- APLICAÇÃO PRINCIPAL (Utilizador está logado) ---
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>" />
<title>NoteKit — Alternativa minimalista ao Evernote</title>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<style>
    :root {
        --bg:#0b0f13; --panel:#141a22; --text:#e8edf2; --muted:#8a94a4;
        --border:#1c232c; --accent:#3a9fff; --accent-2:#9b6bff;
        --ok:#34d399; --danger:#ef4444; --warn:#fbbf24;
        --ink:#0e141b; --ink-strong:#19212c;
        --shadow-color: rgba(0,0,0,0.3);
    }
    * { box-sizing:border-box; }
    html,body { height:100%; margin:0; background:var(--bg); color:var(--text); font:15px/1.5 "Inter",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,sans-serif; }

    .app {
        display:grid;
        grid-template-columns: 280px 380px 1fr;
        grid-template-rows: 1fr;
        grid-template-areas: "sidebar notes editor";
        gap: 16px;
        height: 100vh;
        padding: 16px;
    }
    .card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 18px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 24px var(--shadow-color);
        transition: transform .2s ease, box-shadow .2s ease;
    }
    .card:hover { transform: translateY(-2px); box-shadow: 0 12px 32px var(--shadow-color); }
    .sidebar { grid-area: sidebar; }
    .notes { grid-area: notes; }
    .editor { grid-area: editor; }

    .hdr { padding:16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .hdr h2 { margin:0; font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); }
    .body { flex:1; padding:16px; overflow:auto; position:relative; }
    .footer { padding:12px 16px; border-top:1px solid var(--border); display:flex; gap:8px; align-items:center; }

    .btn { cursor:pointer; border:none; border-radius:10px; padding:8px 12px; background:var(--ink); color:var(--text); transition:background .15s ease, transform .02s; display:inline-flex; align-items:center; justify-content:center; gap: 6px; }
    .btn:hover { background:var(--ink-strong); }
    .btn:active { transform:translateY(1px); }
    .btn.primary { background:linear-gradient(135deg,var(--accent),var(--accent-2)); border:none; color:#fff; }
    .btn.danger { color:#fca5a5; border:1px solid #3b1a1a; background:transparent; }
    .btn.ok { color:#6ee7b7; border:1px solid #113426; background:transparent; }
    .btn:disabled { opacity:0.5; cursor:not-allowed; transform:none; }
    .btn:disabled:hover { background: var(--ink); }

    .input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--ink); color:var(--text); transition: border-color .2s ease; }
    .input:focus { outline:1.5px solid var(--accent); border-color: var(--accent); }

    .pill { display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); cursor:pointer; margin:2px; user-select:none; transition: all .2s; }
    .pill:hover { border-color: var(--accent); color: var(--text); }
    .pill.active { border-color:var(--accent); color:var(--text); background:#182030; font-weight: 500; }
    .pill .del-nb { margin-left:5px; color:#fca5a5; font-weight:bold; cursor:pointer; }

    .note-item { border:1px solid var(--border); border-radius:12px; padding:12px; margin-bottom:10px; background:#10171f; cursor:pointer; transition:background .2s, transform .2s; display:grid; grid-template-columns:1fr auto; gap:6px; }
    .note-item:hover { background:#1a2431; transform:translateX(3px); }
    .note-item .title { font-weight:600; color: var(--text); }
    .note-item .meta { color:var(--muted); font-size:12px; margin-top:4px; }
    .pin { color:var(--warn); font-size:12px; }

    .tag { background:#16202c; border:1px solid var(--border); border-radius:999px; padding:5px 9px; margin:2px; display:inline-block; }
    .tag .x { color:#fca5a5; margin-left:5px; cursor:pointer; }

    .row { display:flex; gap:8px; align-items:center; }
    .grow { flex:1; }

    #status { color:var(--muted); font-size:13px; }

    .toast { position:fixed; right:16px; bottom:16px; background:#0f1722; color:#e6edf7; border:1px solid #223046; padding:10px 12px; border-radius:10px; box-shadow:0 4px 16px #0007; opacity:0; transform:translateY(8px); transition:.2s; z-index: 1000; }
    .toast.show { opacity:1; transform:translateY(0); }

    .attachment-item { display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:var(--ink); border:1px solid var(--border); border-radius:8px; margin-bottom:4px; font-size:14px; transition: background .2s; }
    .attachment-item:hover { background: var(--ink-strong); }
    .attachment-item .name { margin-right:10px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .attachment-item a { color:var(--accent); text-decoration:none; }
    .attachment-item a:hover { text-decoration:underline; }
    .attachment-item .delete-btn { color:var(--danger); margin-left:10px; cursor:pointer; font-weight:bold; }
    
    /* Responsividade */
    @media (max-width: 1200px) {
        .app { grid-template-columns: 240px 1fr 1fr; }
    }
    @media (max-width: 1024px) {
        .app {
            grid-template-columns: 260px 1fr;
            grid-template-rows: auto 1fr;
            grid-template-areas: "sidebar editor" "notes editor";
        }
    }
    @media (max-width: 768px) {
        .app {
            grid-template-columns: 1fr;
            grid-template-rows: auto auto 1fr;
            grid-template-areas: "sidebar" "notes" "editor";
            height: auto; min-height: 100vh;
            padding: 10px;
            gap: 10px;
        }
        .card { flex-shrink: 0; }
        .editor .body { min-height: 500px; /* Garante que o editor tenha espaço */}
    }

    /* Estilos Dark para TinyMCE */
    .tox-tinymce { border-radius:10px !important; border-color:var(--border) !important; }
    .tox .tox-toolbar-overlord, .tox .tox-menubar, .tox .tox-statusbar { background-color:var(--ink-strong) !important; color:var(--text) !important; border-color:var(--border) !important; }
    .tox .tox-editor-header { background-color:var(--ink-strong) !important; }
    .tox .tox-notification--in.tox-notification--warning { background-color:var(--warn) !important; color:var(--ink-strong) !important; }
    .tox:not([dir=rtl]) .tox-toolbar__group:not(:last-of-type) { border-right-color:var(--border) !important; }
    .tox .tox-toolbar button:hover, .tox .tox-tbtn:hover, .tox .tox-split-button:hover { background-color:var(--ink) !important; }
    .tox .tox-menu-nav__js:hover { background-color:var(--ink) !important; }
    .tox .tox-collection__item { color:var(--text) !important; }
    .tox .tox-collection__item:hover { background-color:var(--ink-strong) !important; }
</style>
</head>
<body>
<div class="app">
    <section class="card sidebar">
        <div class="hdr"><h2>Navegação</h2> <a href="?api=logout" class="btn danger" style="padding:4px 8px;font-size:12px;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>Sair</a></div>
        <div class="body" style="display:flex;flex-direction:column;gap:16px">
            <input id="q" class="input" placeholder="🔎 Buscar (Ctrl+K)" />
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Cadernos</div>
                <div id="notebooks"></div>
                <div class="row" style="margin-top:8px">
                    <input id="newNotebook" class="input grow" placeholder="Novo caderno" />
                    <button class="btn ok" id="btnAddNotebook" title="Criar caderno">＋</button>
                </div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Tags</div>
                <div id="tags"></div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Filtros rápidos</div>
                <div class="pill" data-filter="pinned">📌 Fixadas</div>
                <div class="pill" data-filter="archived">📦 Arquivadas</div>
                <div class="pill active" data-filter="all">🗂️ Todas</div>
            </div>
        </div>
    </section>

    <section class="card notes">
        <div class="hdr"><h2>Notas</h2><button class="btn ok" id="btnNew" title="Nova nota">＋ Nova</button></div>
        <div class="body">
            <div id="noteList"></div>
        </div>
        <div class="footer">
            <button class="btn" id="btnPrev" disabled>Anterior</button>
            <button class="btn" id="btnNext" disabled>Próximo</button>
        </div>
    </section>

    <section class="card editor">
        <div class="hdr"><h2>Editor Rich Text</h2>
            <div>
                <button class="btn" id="btnSave" title="Salvar agora (Auto-save está ativo)">💾 Salvar</button>
            </div>
        </div>
        <div class="body" style="display: flex; flex-direction: column; gap: 12px;">
            <div class="row">
                <input id="title" class="input grow" placeholder="Título" />
                <button class="btn" id="btnPin" title="Fixar">📌</button>
                <button class="btn" id="btnArchive" title="Arquivar">📦</button>
                <button class="btn danger" id="btnDelete" title="Excluir">🗑️</button>
            </div>
            <select id="notebook" class="input"></select>
            
            <textarea id="content" class="input" placeholder="Escreva sua nota (Rich Text)"></textarea>

            <div style="border-top:1px solid var(--border); padding-top: 12px;">
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Anexos</div>
                <div id="attachmentList" style="margin-bottom:8px"></div>
                <input type="file" id="fileAttachment" style="display:none;" />
                <button class="btn primary" id="btnChooseAttachment" disabled style="width:100%;">Anexar Arquivo</button>
            </div>
            
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">Tags</div>
                <div id="tagBox"></div>
                <div class="row" style="margin-top:6px">
                    <input id="tagInput" class="input grow" placeholder="Digite e Enter" />
                    <button class="btn" id="btnClearTags" title="Limpar tags">Limpar</button>
                </div>
            </div>
        </div>
        <div class="footer">
            <div id="status">Pronto.</div>
        </div>
    </section>
</div>
<div id="toast" class="toast"></div>

<script>
// Utilitários de API
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

const api = (name, params={}) => {
  const url = new URL(location.href);
  url.searchParams.set('api', name);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  return fetch(url, {credentials:'same-origin'}).then(r => r.json());
};
const apiJSON = (name, body={}) => {
  const url = new URL(location.href);
  url.searchParams.set('api', name);
  return fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN},
    body: JSON.stringify(body)
  }).then(r => r.json());
};

// Estado
let state = {
  filter: { q:'', notebook_id:null, tag:null, archived:false, pinned:false, page:1 },
  notes: [], notebooks: [], tags: [],
  current: null,
  attachments: [],
  autoSaveTimer: null,
};

// Helpers de DOM
const el = sel => document.querySelector(sel);
const els = sel => Array.from(document.querySelectorAll(sel));
function toast(msg){ const t=el('#toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'), 2000); }
function setStatus(msg){ el('#status').textContent = msg; }
function escapeHTML(s){ return (s+"").replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }
function htmlToSnippet(html, maxLen=180) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const text = doc.body.textContent || "";
    return text.slice(0, maxLen).replace(/\n/g,' ');
}

// Inicializa TinyMCE
let editorInstance = null;
function initEditor() {
    tinymce.init({
        selector: '#content',
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount codesample contextmenu paste autoresize',
        toolbar: 'undo redo | formatselect | bold italic backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | image media link codesample | code fullscreen preview help',
        skin: 'oxide-dark',
        content_css: 'dark',
        promotion: false,
        height: 400,
        menubar: false,
        autoresize_bottom_margin: 10,
        images_upload_url: '?api=uploadFile',
        images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?api=uploadFile');
            const formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            xhr.onload = () => {
                if (xhr.status !== 200) return reject('Erro HTTP: ' + xhr.status);
                const json = JSON.parse(xhr.responseText);
                if (!json || typeof json.location !== 'string') return reject('Resposta de upload inválida: ' + xhr.responseText);
                resolve(json.location);
            };
            xhr.onerror = () => reject('Erro de rede ao tentar carregar.');
            xhr.send(formData);
        }),
        setup: (editor) => {
            editorInstance = editor;
            editor.on('input change', triggerAutoSave);
        }
    });
}

// Carregar tudo
async function loadAll(){
  initEditor();
  const [notebooks, tags] = await Promise.all([api('listNotebooks'), api('listTags')]);
  state.notebooks = notebooks; state.tags = tags; renderNotebooks(); renderTags();
  await refreshNotes();
}

async function refreshNotes(){
  setStatus('Carregando notas...');
  const params = {
    q: state.filter.q || '',
    notebook_id: state.filter.notebook_id || '',
    tag: state.filter.tag || '',
    archived: state.filter.archived ? '1':'0',
    pinned: state.filter.pinned ? '1':'0',
    page: state.filter.page || 1,
  };
  const res = await api('listNotes', params);
  if (res.error) { toast(res.error); setStatus('Erro.'); return; }
  
  state.notes = res; 
  renderNoteList();
  setStatus('Pronto.');
  
  el('#btnPrev').disabled = (state.filter.page <= 1);
  el('#btnNext').disabled = (res.length < 50);
}

// Notebooks
function renderNotebooks(){
  const box = el('#notebooks'); box.innerHTML = '';
  const all = document.createElement('div'); all.className='pill'; all.textContent='Todos';
  all.onclick = ()=>{ state.filter.notebook_id=null; state.filter.page=1; refreshNotes(); highlightNotebooks(); };
  box.appendChild(all);
  
  state.notebooks.forEach(nb => {
    const s = document.createElement('div'); s.className='pill';
    s.innerHTML = `${escapeHTML(nb.name)} <span class="del-nb" data-id="${nb.id}" data-name="${escapeHTML(nb.name)}">[X]</span>`;
    s.title='Clique para filtrar / Duplo clique para renomear';
    s.querySelector('.del-nb').onclick = (e) => { e.stopPropagation(); deleteNotebook(nb.id, nb.name); };
    s.onclick = ()=>{ state.filter.notebook_id = nb.id; state.filter.page=1; refreshNotes(); highlightNotebooks(); };
    s.ondblclick = async ()=>{
      const name = prompt('Novo nome do caderno:', nb.name); if(!name) return;
      await api('renameNotebook', { id: nb.id, name });
      state.notebooks = await api('listNotebooks'); renderNotebooks();
    };
    box.appendChild(s);
  });
  const sel = el('#notebook'); sel.innerHTML = '<option value="">Sem caderno</option>' + state.notebooks.map(n=>`<option value="${n.id}">${escapeHTML(n.name)}</option>`).join('');
  highlightNotebooks();
}
function highlightNotebooks(){
  const id = state.filter.notebook_id;
  els('#notebooks .pill').forEach((p,i)=>{ 
      if(i === 0) { p.classList.toggle('active', id === null); return; }
      p.classList.toggle('active', state.notebooks[i-1].id === id); 
  });
}
async function deleteNotebook(id, name) {
    if (!confirm(`Tem certeza que deseja apagar o caderno "${name}"?\nAs notas deste caderno NÃO serão apagadas, mas ficarão "Sem caderno".`)) return;
    await api('deleteNotebook', {id});
    toast('Caderno apagado.');
    state.notebooks = await api('listNotebooks'); renderNotebooks();
    if(state.filter.notebook_id === id) { state.filter.notebook_id = null; refreshNotes(); }
}

// Tags
function renderTags(){
  const box = el('#tags'); box.innerHTML = '';
  state.tags.forEach(t => {
    const s = document.createElement('div'); s.className='pill'; s.textContent=t.name; s.onclick=()=>{ state.filter.tag = t.name; state.filter.page=1; refreshNotes(); highlightTags(); };
    box.appendChild(s);
  });
  highlightTags();
}
function highlightTags(){ els('#tags .pill').forEach(p=> p.classList.toggle('active', p.textContent===state.filter.tag)); }

// Lista de Notas
function renderNoteList(){
  const box = el('#noteList'); box.innerHTML = '';
  if(!state.notes.length){ box.innerHTML = '<div style="color:var(--muted); text-align:center; padding: 20px;">Nenhuma nota encontrada.</div>'; return; }
  state.notes.forEach(n => {
    const div = document.createElement('div'); div.className='note-item';
    const title = escapeHTML(n.title || '(sem título)');
    const snippet = htmlToSnippet(n.content||'');
    div.innerHTML = `
      <div>
        <div class="title">${title} ${n.pinned?'<span class="pin">📌</span>':''}</div>
        <div class="meta">${n.updated_at} ${n.archived?' · arquivada':''}</div>
        <div style="color:var(--muted); font-size: 14px; margin-top: 4px;">${snippet}</div>
      </div>
      <div class="meta" style="text-align:right;">${escapeHTML(n.tags ? n.tags.join(', ') : '')}</div>
    `;
    div.onclick = ()=> openNote(n.id);
    box.appendChild(div);
  });
}

// Editor
async function openNote(id){
  const n = await api('getNote', {id});
  if (n.error) { toast(n.error); return; }
  state.current = n;
  el('#title').value = n.title || '';
  el('#notebook').value = n.notebook_id || '';
  renderTagBox(n.tags || []); 

  if (editorInstance) { editorInstance.setContent(n.content || ''); }

  el('#btnPin').dataset.pinned = n.pinned ? '1':'0';
  el('#btnArchive').dataset.archived = n.archived ? '1':'0';
  
  await refreshAttachments(id);
}

function renderTagBox(tags){
  const box = el('#tagBox'); box.innerHTML = '';
  tags.forEach(t=>{
    const span = document.createElement('span'); span.className='tag';
    span.innerHTML = `${escapeHTML(t)} <span class="x" title="Remover">×</span>`;
    span.querySelector('.x').onclick=()=>{ 
      const arr = getEditorTags().filter(x=>x!==t); renderTagBox(arr);
      triggerAutoSave();
    };
    box.appendChild(span);
  });
}
function getEditorTags(){
  return Array.from(el('#tagBox').querySelectorAll('.tag'))
    .map(x=>x.textContent.replace('×','').trim()).filter(Boolean);
}

// Anexos
function updateAttachmentUIState() {
    el('#btnChooseAttachment').disabled = !state.current?.id;
}

async function refreshAttachments(note_id){
    if(!note_id) { 
        state.attachments = []; 
        renderAttachments();
        updateAttachmentUIState();
        return;
    }
    updateAttachmentUIState();
    const res = await api('listAttachments', {note_id});
    if (res.error) { toast(res.error); state.attachments = []; }
    else { state.attachments = res; }
    renderAttachments();
}

function renderAttachments(){
    const box = el('#attachmentList'); box.innerHTML = '';
    if(!state.attachments.length) { box.innerHTML = '<div style="color:var(--muted);font-style:italic;font-size:14px;">Nenhum arquivo anexado.</div>'; return; }
    state.attachments.forEach(att => {
        const div = document.createElement('div'); div.className='attachment-item';
        const url = `${att.file_path}`;
        div.innerHTML = `
            <div class="name">
                <a href="${url}" target="_blank" title="Baixar/Visualizar">${escapeHTML(att.original_name)}</a> 
                <span style="color:var(--muted);font-size:12px;">(${att.mime_type})</span>
            </div>
            <span class="delete-btn" data-id="${att.id}">🗑️</span>
        `;
        div.querySelector('.delete-btn').onclick = ()=> deleteAttachment(att.id);
        box.appendChild(div);
    });
}

async function deleteAttachment(id){
    if (!confirm('Excluir permanentemente este anexo?')) return;
    const res = await api('deleteAttachment', {id});
    if (res.error) { toast(`Erro ao excluir anexo: ${res.error}`); return; }
    toast('Anexo excluído.');
    await refreshAttachments(state.current.id);
}

// Novo fluxo de upload: Botão dispara input, que dispara upload
el('#btnChooseAttachment').onclick = () => {
    // Se o botão não estiver desabilitado, clica no input de arquivo escondido
    if (!el('#btnChooseAttachment').disabled) {
        el('#fileAttachment').click();
    }
};

el('#fileAttachment').onchange = async () => {
    if (!state.current?.id || !el('#fileAttachment').files.length) return;
    setStatus('Enviando arquivo...');
    
    const file = el('#fileAttachment').files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('note_id', state.current.id);

    try {
        const url = new URL(location.href);
        url.searchParams.set('api', 'uploadFile');
        const res = await fetch(url, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.error) throw new Error(data.error);
        
        toast('Arquivo anexado com sucesso.');
        await refreshAttachments(state.current.id);
    } catch (e) {
        toast(e.message);
    } finally {
        setStatus('Pronto.');
        el('#fileAttachment').value = ''; // Limpa o input para permitir o mesmo arquivo de novo
    }
};


// Ações
el('#btnAddNotebook').onclick = async ()=>{
  const name = el('#newNotebook').value.trim(); if(!name) return;
  await api('createNotebook', {name});
  el('#newNotebook').value='';
  state.notebooks = await api('listNotebooks'); renderNotebooks(); setStatus('Caderno criado.');
};

el('#btnNew').onclick = ()=>{ 
    state.current=null; 
    el('#title').value=''; el('#notebook').value=''; 
    if(editorInstance) { editorInstance.setContent(''); }
    renderTagBox([]); 
    refreshAttachments(null);
};

el('#btnSave').onclick = saveCurrent;
async function saveCurrent(){
  if (state.autoSaveTimer) clearTimeout(state.autoSaveTimer);
  setStatus('Salvando...');

  const content = editorInstance ? editorInstance.getContent() : '';

  const payload = {
    id: state.current?.id || null,
    title: el('#title').value.trim(),
    content: content,
    notebook_id: el('#notebook').value || null,
    tags: getEditorTags(),
  };
  const res = await apiJSON('saveNote', payload);
  if (res.error) { setStatus('Erro ao salvar.'); toast(res.error); return; }
  
  setStatus('Salvo.');
  setTimeout(()=>setStatus('Pronto.'), 1600);
  
  await refreshNotes();
  if(res?.id && !state.current) { 
      await openNote(res.id); 
  }
  else if (res?.id) { 
      state.current.id = res.id; 
      updateAttachmentUIState();
  }
}

el('#btnDelete').onclick = async ()=>{
  if(!state.current?.id) return; 
  if(!confirm('Excluir permanentemente esta nota? Isso também excluirá os anexos de arquivo.')) return;
  
  await api('deleteNote', {id: state.current.id});
  setStatus('Excluída.');
  state.current=null; el('#title').value=''; el('#notebook').value=''; 
  if(editorInstance) { editorInstance.setContent(''); }
  renderTagBox([]);
  refreshAttachments(null); 
  await refreshNotes();
};

el('#btnArchive').onclick = async ()=>{
  if(!state.current?.id) return; 
  const now = el('#btnArchive').dataset.archived === '1' ? false : true;
  await api('archiveNote', {id: state.current.id, archived: now?'1':'0'});
  el('#btnArchive').dataset.archived = now?'1':'0';
  setStatus(now?'Arquivada.':'Desarquivada.');
  await refreshNotes();
};

el('#btnPin').onclick = async ()=>{
  if(!state.current?.id) return; 
  const now = el('#btnPin').dataset.pinned === '1' ? false : true;
  await api('pinNote', {id: state.current.id, pinned: now?'1':'0'});
  el('#btnPin').dataset.pinned = now?'1':'0';
  setStatus(now?'Fixada.':'Desfixada.');
  await refreshNotes();
};

// Busca e Tags (editor)
el('#q').addEventListener('keydown', e=>{ if(e.key==='Enter'){ state.filter.q = el('#q').value.trim(); state.filter.page=1; refreshNotes(); }});
el('#tagInput').addEventListener('keydown', e=>{
  if(e.key==='Enter'){
    e.preventDefault();
    const v = el('#tagInput').value.trim(); if(!v) return;
    const tags = Array.from(new Set([...getEditorTags(), v]));
    renderTagBox(tags); el('#tagInput').value='';
    triggerAutoSave();
  }
});
el('#btnClearTags').onclick = ()=> { renderTagBox([]); triggerAutoSave(); };

// Filtros rápidos
els('.pill[data-filter]').forEach(p=> p.onclick = ()=>{
  const f = p.dataset.filter;
  state.filter.archived = f==='archived';
  state.filter.pinned   = f==='pinned';
  if(f==='all'){ state.filter.archived=false; state.filter.pinned=false; }
  state.filter.page=1;
  refreshNotes();
  els('.pill[data-filter]').forEach(x=>x.classList.remove('active')); p.classList.add('active');
});

// Paginação
el('#btnPrev').onclick = ()=>{ if(state.filter.page > 1) { state.filter.page--; refreshNotes(); } };
el('#btnNext').onclick = ()=>{ state.filter.page++; refreshNotes(); };

// Atalhos
window.addEventListener('keydown', (e)=>{
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s') { e.preventDefault(); saveCurrent(); }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k') { e.preventDefault(); el('#q').focus(); }
});

// Auto-Save
function triggerAutoSave() {
    if (state.autoSaveTimer) clearTimeout(state.autoSaveTimer);
    const content = editorInstance ? editorInstance.getContent() : '';
    if (!state.current && !el('#title').value && !content) return;
    setStatus('Digitando...');
    state.autoSaveTimer = setTimeout(saveCurrent, 1500);
}

el('#title').addEventListener('input', triggerAutoSave);
el('#notebook').addEventListener('change', triggerAutoSave);

loadAll();
</script>
</body>
</html>

<?php
//------------------------------------------------------------
// FUNÇÕES PHP (API)
//------------------------------------------------------------

//------------------------------------------------------------
// AUTENTICAÇÃO
//------------------------------------------------------------
function handle_login(PDO $pdo, array $data): array {
    $user = $data['user'] ?? '';
    $pass = $data['pass'] ?? '';
    
    $st = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$user]);
    $db_user = $st->fetch();

    if ($db_user && password_verify($pass, $db_user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = $db_user['username'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return ['ok' => 1];
    } else {
        throw new Exception('Utilizador ou senha inválidos.');
    }
}

//------------------------------------------------------------
// BANCO / SCHEMA
//------------------------------------------------------------
function ensure_schema(PDO $pdo): void {
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS notebooks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notebook_id INT NULL,
        title VARCHAR(255) NULL,
        content MEDIUMTEXT NULL,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        pinned TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (notebook_id) REFERENCES notebooks(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS note_tags (
        note_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY(note_id, tag_id),
        FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hash = '$2y$10$iGgB5m9v.P.sL1YjCgDFteT/X1l/kMvBntGgA5hH.Ca.hAaThNlga'; // admin123
    $st = $pdo->prepare("INSERT IGNORE INTO users (username, password_hash) VALUES (?, ?)");
    $st->execute(['admin', $hash]);

    try { $pdo->exec("ALTER TABLE notes ADD FULLTEXT ft_title_content (title, content)"); } catch(Throwable $e){}
}

//------------------------------------------------------------
// NOTEBOOKS
//------------------------------------------------------------
function list_notebooks(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM notebooks ORDER BY name")->fetchAll();
}
function create_notebook(PDO $pdo, string $name): array { 
    if($name==='') throw new Exception('Nome obrigatório');
    $st=$pdo->prepare("INSERT INTO notebooks(name) VALUES (?)"); $st->execute([$name]);
    return ['id'=>$pdo->lastInsertId(), 'name'=>$name];
}
function rename_notebook(PDO $pdo, int $id, string $name): array { 
    if($name==='') throw new Exception('Nome obrigatório');
    $st=$pdo->prepare("UPDATE notebooks SET name=? WHERE id=?"); $st->execute([$name,$id]);
    return ['ok'=>1];
}
function delete_notebook(PDO $pdo, int $id): array {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE notes SET notebook_id=NULL WHERE notebook_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM notebooks WHERE id=?")->execute([$id]);
    $pdo->commit();
    return ['ok'=>1];
}

//------------------------------------------------------------
// TAGS
//------------------------------------------------------------
function list_tags(PDO $pdo): array { return $pdo->query("SELECT id, name FROM tags ORDER BY name")->fetchAll(); }
function tag_id(PDO $pdo, string $name): int {
    $st=$pdo->prepare("SELECT id FROM tags WHERE name=?"); $st->execute([$name]); $id = $st->fetchColumn();
    if($id) return (int)$id;
    $st=$pdo->prepare("INSERT INTO tags(name) VALUES (?)"); $st->execute([$name]); return (int)$pdo->lastInsertId();
}
function tags_for_note(PDO $pdo, int $note_id): array {
    $st=$pdo->prepare("SELECT t.name FROM note_tags nt JOIN tags t ON t.id=nt.tag_id WHERE nt.note_id=? ORDER BY t.name");
    $st->execute([$note_id]);
    return array_column($st->fetchAll(), 'name');
}
function set_note_tags(PDO $pdo, int $note_id, array $tags): void {
    $tags = array_values(array_unique(array_filter(array_map(fn($t)=> trim((string)$t), $tags))));
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM note_tags WHERE note_id=?")->execute([$note_id]);
    foreach($tags as $name){
        $tid = tag_id($pdo, $name);
        $pdo->prepare("INSERT IGNORE INTO note_tags(note_id, tag_id) VALUES (?,?)")->execute([$note_id, $tid]);
    }
    $pdo->commit();
}

//------------------------------------------------------------
// NOTAS
//------------------------------------------------------------
function list_notes(PDO $pdo): array {
    $q = trim($_GET['q'] ?? '');
    $notebook_id = (int)($_GET['notebook_id'] ?? 0);
    $tag = trim($_GET['tag'] ?? '');
    $archived = ($_GET['archived'] ?? '0')==='1';
    $pinned   = ($_GET['pinned'] ?? '0')==='1';
    
    $page = (int)($_GET['page'] ?? 1);
    if($page < 1) $page = 1;
    $limit = 50;
    $offset = ($page - 1) * $limit;


    $sql = "SELECT n.id, n.title, n.content, n.archived, n.pinned, n.notebook_id,
                   DATE_FORMAT(n.updated_at,'%Y-%m-%d %H:%i') AS updated_at,
                   (
                     SELECT JSON_ARRAYAGG(t.name)
                     FROM note_tags nt JOIN tags t ON t.id=nt.tag_id WHERE nt.note_id=n.id
                   ) AS tags
            FROM notes n";

    $conds = [];$params=[];
    if($archived) $conds[] = 'n.archived=1'; else $conds[]='n.archived=0';
    if($pinned)   $conds[] = 'n.pinned=1';
    if($notebook_id) { $conds[]='n.notebook_id=?'; $params[]=$notebook_id; }
    if($tag!=='') {
        $sql .= " JOIN note_tags nt ON nt.note_id=n.id JOIN tags t ON t.id=nt.tag_id";
        $conds[] = 't.name=?'; $params[]=$tag;
    }
    if($q!==''){
        $hasFT = false;
        try {
            $idx = $pdo->query("SHOW INDEX FROM notes WHERE Key_name='ft_title_content'")->fetchAll();
            if($idx) $hasFT = true;
        } catch(Throwable $e){ $hasFT = false; }
        if($hasFT){
            $conds[] = 'MATCH(n.title, n.content) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $q;
        } else {
            $q_like = "%$q%";
            $conds[] = '(n.title LIKE ? OR n.content LIKE ?)';
            $params[] = $q_like; $params[] = $q_like;
        }
    }
    if($conds) $sql .= ' WHERE '.implode(' AND ', $conds);
    $sql .= " ORDER BY n.pinned DESC, n.updated_at DESC LIMIT $limit OFFSET $offset";

    $st = $pdo->prepare($sql); $st->execute($params);
    $notes = $st->fetchAll();
    
    foreach($notes as &$note) {
        $note['tags'] = $note['tags'] ? json_decode($note['tags']) : [];
    }
    
    return $notes;
}

function get_note(PDO $pdo, int $id): array {
    $st=$pdo->prepare("SELECT id, notebook_id, title, content, archived, pinned,
        DATE_FORMAT(updated_at,'%Y-%m-%d %H:%i') AS updated_at FROM notes WHERE id=?");
    $st->execute([$id]); $note = $st->fetch();
    if(!$note) throw new Exception('Nota não encontrada');
    $note['tags'] = tags_for_note($pdo, $id);
    return $note;
}

function save_note(PDO $pdo, array $data): array {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $title = trim((string)($data['title'] ?? ''));
    $content = (string)($data['content'] ?? '');
    $notebook_id = $data['notebook_id'] ? (int)$data['notebook_id'] : null;
    $tags = $data['tags'] ?? []; 

    if ($title === '' && $content === '' && $id === 0) {
        throw new Exception('Não é possível salvar nota vazia.');
    }

    if($id>0){
        $st=$pdo->prepare("UPDATE notes SET title=?, content=?, notebook_id=? WHERE id=?");
        $st->execute([$title, $content, $notebook_id, $id]);
    } else {
        $st=$pdo->prepare("INSERT INTO notes(title, content, notebook_id) VALUES (?,?,?)");
        $st->execute([$title, $content, $notebook_id]);
        $id = (int)$pdo->lastInsertId();
    }
    set_note_tags($pdo, $id, $tags);
    return ['id'=>$id];
}

function delete_note(PDO $pdo, int $id): array {
    $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
    return ['ok'=>1];
}

function archive_note(PDO $pdo, int $id, bool $archived): array {
    $st=$pdo->prepare("UPDATE notes SET archived=? WHERE id=?"); $st->execute([$archived?1:0,$id]);
    return ['ok'=>1];
}

function pin_note(PDO $pdo, int $id, bool $pinned): array {
    $st=$pdo->prepare("UPDATE notes SET pinned=? WHERE id=?"); $st->execute([$pinned?1:0,$id]);
    return ['ok'=>1];
}

//------------------------------------------------------------
// UPLOADS E ANEXOS
//------------------------------------------------------------
function list_attachments(PDO $pdo, int $note_id): array {
    $st=$pdo->prepare("SELECT id, original_name, file_path, mime_type FROM attachments WHERE note_id=? ORDER BY created_at DESC");
    $st->execute([$note_id]);
    return $st->fetchAll();
}

function handle_upload_file(PDO $pdo): array {
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro ao receber o arquivo: ' . ($file ? $file['error'] : 'Arquivo ausente'));
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('O arquivo excede o limite de tamanho (' . round(MAX_FILE_SIZE / 1024 / 1024) . 'MB).');
    }

    $note_id = (int)($_POST['note_id'] ?? 0);
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe_name = uniqid('file_', true) . '.' . strtolower($ext);
    $destination = UPLOADS_DIR . $safe_name;

    if (!is_dir(UPLOADS_DIR)) {
        if (!mkdir(UPLOADS_DIR, 0777, true)) {
            throw new Exception('Falha ao criar diretório de uploads. Verifique as permissões.');
        }
    }
    
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Falha ao salvar o arquivo no servidor. Verifique as permissões de escrita.');
    }
    
    $file_path = UPLOADS_DIR . $safe_name;

    if ($note_id > 0) {
        $st = $pdo->prepare("INSERT INTO attachments(note_id, original_name, file_path, mime_type) VALUES (?, ?, ?, ?)");
        $st->execute([$note_id, $file['name'], $file_path, $file['type']]);
    }

    return ['location' => $file_path];
}

function delete_attachment(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT file_path FROM attachments WHERE id=?");
    $st->execute([$id]);
    $attachment = $st->fetch();

    if(!$attachment) throw new Exception('Anexo não encontrado.');
    
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$id]);
    $pdo->commit();

    if(file_exists($attachment['file_path'])) {
        @unlink($attachment['file_path']);
    }

    return ['ok'=>1];
}

//------------------------------------------------------------
// HELPERS
//------------------------------------------------------------
function json($data){ echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function read_json(): array { $raw=file_get_contents('php://input'); return $raw? json_decode($raw,true)??[] : []; }
function require_int(string $key): int { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return (int)$_REQUEST[$key]; }
function require_str(string $key): string { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return trim((string)$_REQUEST[$key]); }
function require_bool(string $key): bool { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return in_array($_REQUEST[$key],[1,'1','true','on'],true); }
?>
