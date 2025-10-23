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
 */

//------------------------------------------------------------
// CONFIGURAÇÃO
//------------------------------------------------------------
const DB_DSN  = 'mysql:host=localhost;dbname=notekit;charset=utf8mb4';
const DB_USER = 'notekit';
const DB_PASS = 'PL2VYNN0E5Hw4PGsOuTv';

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
    
    // Inicializa o PDO mais cedo, pois o login também precisa dele
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

    // Proteção CSRF para ações POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    form { background:var(--panel); border:1px solid var(--border); border-radius:16px; padding:24px; width:300px; box-shadow:0 2px 10px #0005; }
    .input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--ink); color:var(--text); box-sizing:border-box; margin-bottom:12px; }
    .btn { cursor:pointer; border:none; border-radius:10px; padding:10px 12px; background:var(--accent); color:#fff; width:100%; font-weight:600; }
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
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
    :root {
        --bg:#0b0f13; --panel:#141a22; --text:#e8edf2; --muted:#8a94a4;
        --border:#1c232c; --accent:#3a9fff; --accent-2:#9b6bff;
        --ok:#34d399; --danger:#ef4444; --warn:#fbbf24;
        --ink:#0e141b; --ink-strong:#19212c;
    }
    * { box-sizing:border-box; }
    html,body { height:100%; margin:0; background:var(--bg); color:var(--text); font:15px/1.5 "Inter",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,sans-serif; }

    .app { display:grid; grid-template-columns:260px 360px 1fr; gap:12px; height:100vh; padding:14px; }
    .card { background:var(--panel); border:1px solid var(--border); border-radius:16px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 2px 10px #0005; }
    .hdr { padding:14px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .hdr h2 { margin:0; font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); }
    .body { flex:1; padding:14px; overflow:auto; position:relative; } /* position:relative para preview */
    .footer { padding:10px 14px; border-top:1px solid var(--border); display:flex; gap:8px; align-items:center; }

    .btn { cursor:pointer; border:none; border-radius:10px; padding:8px 12px; background:var(--ink); color:var(--text); transition:background .15s ease, transform .02s; }
    .btn:hover { background:var(--ink-strong); }
    .btn:active { transform:translateY(1px); }
    .btn.primary { background:linear-gradient(135deg,var(--accent),var(--accent-2)); border:none; color:#fff; }
    .btn.danger { color:#fca5a5; border:1px solid #3b1a1a; background:transparent; }
    .btn.ok { color:#6ee7b7; border:1px solid #113426; background:transparent; }
    .btn:disabled { opacity:0.5; cursor:not-allowed; }

    .input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--ink); color:var(--text); }
    .input:focus { outline:1.5px solid var(--accent); }

    .pill { display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); cursor:pointer; margin:2px; user-select:none; }
    .pill.active { border-color:var(--accent); color:var(--text); background:#182030; }
    .pill .del-nb { margin-left:5px; color:#fca5a5; font-weight:bold; cursor:pointer; }

    .note-item { border:1px solid var(--border); border-radius:12px; padding:10px; margin-bottom:10px; background:#10171f; cursor:pointer; transition:background .2s; display:grid; grid-template-columns:1fr auto; gap:6px; }
    .note-item:hover { background:#1a2431; }
    .note-item .title { font-weight:600; }
    .note-item .meta { color:var(--muted); font-size:12px; margin-top:4px; }
    .pin { color:var(--warn); font-size:12px; }

    .tag { background:#16202c; border:1px solid var(--border); border-radius:999px; padding:5px 9px; margin:2px; display:inline-block; }
    .tag .x { color:#fca5a5; margin-left:5px; cursor:pointer; }

    .row { display:flex; gap:8px; align-items:center; }
    .grow { flex:1; }

    #status { color:var(--muted); font-size:13px; }

    .toast { position:fixed; right:16px; bottom:16px; background:#0f1722; color:#e6edf7; border:1px solid #223046; padding:10px 12px; border-radius:10px; box-shadow:0 4px 16px #0007; opacity:0; transform:translateY(8px); transition:.2s; }
    .toast.show { opacity:1; transform:translateY(0); }

    /* Estilos Editor/Preview */
    #content { min-height:280px; resize:vertical; }
    #contentPreview { display:none; position:absolute; inset:14px; overflow:auto; }
    #contentPreview.show { display:block; }
    /* Estilos básicos de Markdown */
    #contentPreview > *:first-child { margin-top:0; }
    #contentPreview h1, #contentPreview h2, #contentPreview h3 { border-bottom:1px solid var(--border); padding-bottom:4px; }
    #contentPreview code { background:var(--ink); padding:2px 5px; border-radius:6px; }
    #contentPreview pre { background:var(--ink); padding:10px; border-radius:8px; overflow-x:auto; }
    #contentPreview blockquote { border-left:3px solid var(--accent); margin-left:0; padding-left:12px; color:var(--muted); }
    #contentPreview ul, #contentPreview ol { padding-left:20px; }
    
    @media (max-width:1100px) { .app { grid-template-columns:240px 1fr; grid-template-rows:1fr 1fr; } .notes{grid-column:2} .editor{grid-column:1 / span 2} }
    @media (max-width:700px) { .app { grid-template-columns:1fr; grid-template-rows:auto auto auto; } }
</style>
</head>
<body>
<div class="app">
    <section class="card sidebar">
        <div class="hdr"><h2>Navegação</h2> <a href="?api=logout" class="btn danger" style="padding:4px 8px;font-size:12px;">Sair</a></div>
        <div class="body" style="display:flex;flex-direction:column;gap:12px">
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
                <div class="pill" data-filter="all">🗂️ Todas</div>
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
        <div class="hdr"><h2>Editor</h2>
            <div>
                <button class="btn" id="btnPreview" title="Pré-visualizar">Ver</button>
                <button class="btn" id="btnSave" title="Salvar agora (Auto-save está ativo)">💾 Salvar</button>
            </div>
        </div>
        <div class="body">
            <div class="row">
                <input id="title" class="input grow" placeholder="Título" />
                <button class="btn" id="btnPin" title="Fixar">📌</button>
                <button class="btn" id="btnArchive" title="Arquivar">📦</button>
                <button class="btn danger" id="btnDelete" title="Excluir">🗑️</button>
            </div>
            <select id="notebook" class="input"></select>
            
            <textarea id="content" class="input" placeholder="Escreva sua nota (Markdown opcional)"></textarea>
            <div id="contentPreview"></div>
            
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Tags</div>
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
  isEditing: true,
  autoSaveTimer: null,
};

// Helpers de DOM
const el = sel => document.querySelector(sel);
const els = sel => Array.from(document.querySelectorAll(sel));
function toast(msg){ const t=el('#toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'), 1600); }
function setStatus(msg){ el('#status').textContent = msg; }
function escapeHTML(s){ return (s+"").replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

// Carregar tudo
async function loadAll(){
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
  
  // Controle de Paginação
  el('#btnPrev').disabled = (state.filter.page <= 1);
  el('#btnNext').disabled = (res.length < 50); // Assumindo limite de 50
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
    
    s.querySelector('.del-nb').onclick = (e) => {
        e.stopPropagation(); // Impede o clique de filtrar
        deleteNotebook(nb.id, nb.name);
    };
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
      if(i === 0) { p.classList.toggle('active', id === null); return; } // Botão "Todos"
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
  if(!state.notes.length){ box.innerHTML = '<div style="color:var(--muted)">Nenhuma nota encontrada.</div>'; return; }
  state.notes.forEach(n => {
    const div = document.createElement('div'); div.className='note-item';
    const title = escapeHTML(n.title || '(sem título)');
    const snippet = escapeHTML((n.content||'').slice(0,180).replace(/\n/g,' '));
    div.innerHTML = `
      <div>
        <div class="title">${title} ${n.pinned?'<span class="pin">📌</span>':''}</div>
        <div class="meta">${n.updated_at} ${n.archived?' · arquivada':''}</div>
        <div style="color:var(--muted)">${snippet}</div>
      </div>
      <div class="meta">${escapeHTML(n.tags ? n.tags.join(', ') : '')}</div>
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
  el('#content').value = n.content || '';
  el('#btnPin').dataset.pinned = n.pinned ? '1':'0';
  el('#btnArchive').dataset.archived = n.archived ? '1':'0';
  el('#notebook').value = n.notebook_id || '';
  renderTagBox(n.tags || []); // API agora retorna array
  showEditor(); // Garante que estamos no modo de edição
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

// Ações
el('#btnAddNotebook').onclick = async ()=>{
  const name = el('#newNotebook').value.trim(); if(!name) return;
  await api('createNotebook', {name});
  el('#newNotebook').value='';
  state.notebooks = await api('listNotebooks'); renderNotebooks(); setStatus('Caderno criado.');
};

el('#btnNew').onclick = ()=>{ 
    state.current=null; 
    el('#title').value=''; el('#content').value=''; el('#notebook').value=''; 
    renderTagBox([]); 
    showEditor();
};

el('#btnSave').onclick = saveCurrent;
async function saveCurrent(){
  if (state.autoSaveTimer) clearTimeout(state.autoSaveTimer);
  setStatus('Salvando...');
  const payload = {
    id: state.current?.id || null,
    title: el('#title').value.trim(),
    content: el('#content').value,
    notebook_id: el('#notebook').value || null,
    tags: getEditorTags(),
  };
  const res = await apiJSON('saveNote', payload);
  if (res.error) { setStatus('Erro ao salvar.'); toast(res.error); return; }
  
  setStatus('Salvo.');
  setTimeout(()=>setStatus('Pronto.'), 1600);
  
  await refreshNotes();
  if(res?.id && !state.current) { openNote(res.id); } // Abre a nova nota
  else if (res?.id) { state.current.id = res.id; } // Atualiza o ID caso seja nova
}

el('#btnDelete').onclick = async ()=>{
  if(!state.current?.id) return; 
  if(!confirm('Excluir permanentemente esta nota?')) return;
  await api('deleteNote', {id: state.current.id});
  setStatus('Excluída.');
  state.current=null; el('#title').value=''; el('#content').value=''; el('#notebook').value=''; renderTagBox([]);
  showEditor();
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
    if (!state.current && !el('#title').value && !el('#content').value) return; // Não salvar nota nova vazia
    setStatus('Digitando...');
    state.autoSaveTimer = setTimeout(saveCurrent, 1500);
}
el('#title').addEventListener('input', triggerAutoSave);
el('#content').addEventListener('input', triggerAutoSave);
el('#notebook').addEventListener('change', triggerAutoSave);

// Preview Markdown
function showEditor() {
    el('#content').style.display = 'block';
    el('#contentPreview').classList.remove('show');
    el('#btnPreview').textContent = 'Ver';
    state.isEditing = true;
}
function showPreview() {
    el('#contentPreview').innerHTML = marked.parse(el('#content').value || '');
    el('#content').style.display = 'none';
    el('#contentPreview').classList.add('show');
    el('#btnPreview').textContent = 'Editar';
    state.isEditing = false;
}
el('#btnPreview').onclick = () => {
    state.isEditing ? showPreview() : showEditor();
};


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
    
    // Consulta o utilizador na nova tabela
    $st = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$user]);
    $db_user = $st->fetch();

    // Verifica o utilizador e o hash da senha
    if ($db_user && password_verify($pass, $db_user['password_hash'])) {
        session_regenerate_id(true); // Previne session fixation
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

    // Nova tabela de utilizadores
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insere o utilizador 'admin' com a senha 'admin123' se ele não existir
    $hash = '$2y$10$iGgB5m9v.P.sL1YjCgDFteT/X1l/kMvBntGgA5hH.Ca.hAaThNlga';
    $st = $pdo->prepare("INSERT IGNORE INTO users (username, password_hash) VALUES (?, ?)");
    $st->execute(['admin', $hash]);

    // Índice FULLTEXT (tenta; ignora se já existir ou se engine não suportar)
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
    // Notas associadas são mantidas, mas perdem a referência (SET NULL)
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
// MODIFICADO: Retorna um array de strings em vez de uma string
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
    
    // Paginação
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
        // tenta FULLTEXT primeiro
        $hasFT = false;
        try {
            $idx = $pdo->query("SHOW INDEX FROM notes WHERE Key_name='ft_title_content'")->fetchAll();
            if($idx) $hasFT = true;
        } catch(Throwable $e){ $hasFT = false; }
        if($hasFT){
            $conds[] = 'MATCH(n.title, n.content) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $q;
        } else {
            $conds[] = '(n.title LIKE ? OR n.content LIKE ?)';
            $params[] = "%$q%"; $params[] = "%$q%";
        }
    }
    if($conds) $sql .= ' WHERE '.implode(' AND ', $conds);
    $sql .= " ORDER BY n.pinned DESC, n.updated_at DESC LIMIT $limit OFFSET $offset";

    $st = $pdo->prepare($sql); $st->execute($params);
    $notes = $st->fetchAll();
    
    // Decodifica o JSON das tags
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
    // MODIFICADO: API agora retorna array
    $note['tags'] = tags_for_note($pdo, $id);
    return $note;
}

function save_note(PDO $pdo, array $data): array {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $title = trim((string)($data['title'] ?? ''));
    $content = (string)($data['content'] ?? '');
    $notebook_id = $data['notebook_id'] ? (int)$data['notebook_id'] : null;
    $tags = $data['tags'] ?? []; // JavaScript agora envia array

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
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM note_tags WHERE note_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
    $pdo->commit();
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
// HELPERS
//------------------------------------------------------------
function json($data){ echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function read_json(): array { $raw=file_get_contents('php://input'); return $raw? json_decode($raw,true)??[] : []; }
function require_int(string $key): int { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return (int)$_REQUEST[$key]; }
function require_str(string $key): string { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return trim((string)$_REQUEST[$key]); }
function require_bool(string $key): bool { if(!isset($_REQUEST[$key])) throw new Exception("'$key' ausente"); return in_array($_REQUEST[$key],[1,'1','true','on'],true); }
?>
