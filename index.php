<?php
/**
 * NoteKit — alternativa ao Evernote (PHP + MySQL + CSS + AJAX)
 * Single-file, completo e pronto para uso.
 * ------------------------------------------------------------
 * Recursos:
 * - Cadernos (notebooks), Notas, Tags, Busca (FULLTEXT se disponível)
 * - Fixar (pinned), Arquivar (soft delete), Excluir
 * - UI SPA com fetch() (sem libs), tema escuro focado
 * - Migração automática do schema na primeira execução
 *
 * Segurança:
 * - Projeto single-user sem login por padrão (proteja com HTTP Basic/.htpasswd se publicar).
 * - Sanitização básica nas listagens; o editor mostra texto bruto.
 * - Para CSRF/Session hardening, adicionar autenticação e token CSRF conforme necessidade.
 */

//------------------------------------------------------------
// CONFIG BANCO — AJUSTADO CONFORME PEDIDO
//------------------------------------------------------------
const DB_DSN  = 'mysql:host=localhost;dbname=notekit;charset=utf8mb4';
const DB_USER = 'notekit';
const DB_PASS = 'PL2VYNN0E5Hw4PGsOuTv';

const ATTACH_DIR = __DIR__ . '/data/attachments';
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024; // 10 MB

if (!is_dir(ATTACH_DIR)) {
    @mkdir(ATTACH_DIR, 0775, true);
}

//------------------------------------------------------------
// BOOTSTRAP
//------------------------------------------------------------
$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

ensure_schema($pdo);

if (isset($_GET['attachment'])) {
    try {
        stream_attachment($pdo, require_int('attachment'));
    } catch (Throwable $e) {
        http_response_code(404);
        echo 'Arquivo não encontrado';
    }
    exit;
}

//-----------------------------
// Roteamento API JSON (AJAX)
//-----------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];
    try {
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
            case 'uploadAttachment': json(upload_attachments($pdo)); break;
            case 'deleteAttachment': json(delete_attachment_api($pdo, read_json())); break;
            default: http_response_code(404); echo json_encode(['error' => 'API desconhecida']);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>NoteKit — Alternativa minimalista ao Evernote</title>
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
    .body { flex:1; padding:14px; overflow:auto; }
    .footer { padding:10px 14px; border-top:1px solid var(--border); display:flex; gap:8px; align-items:center; }

    .btn { cursor:pointer; border:none; border-radius:10px; padding:8px 12px; background:var(--ink); color:var(--text); transition:background .15s ease, transform .02s; }
    .btn:hover { background:var(--ink-strong); }
    .btn:active { transform:translateY(1px); }
    .btn.primary { background:linear-gradient(135deg,var(--accent),var(--accent-2)); border:none; color:#fff; }
    .btn.danger { color:#fca5a5; border:1px solid #3b1a1a; background:transparent; }
    .btn.ok { color:#6ee7b7; border:1px solid #113426; background:transparent; }

    .input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--ink); color:var(--text); }
    .input:focus { outline:1.5px solid var(--accent); }

    .pill { display:inline-block; padding:6px 10px; border:1px solid var(--border); border-radius:999px; color:var(--muted); cursor:pointer; margin:2px; user-select:none; }
    .pill.active { border-color:var(--accent); color:var(--text); background:#182030; }

    .note-item { border:1px solid var(--border); border-radius:12px; padding:10px; margin-bottom:10px; background:#10171f; cursor:pointer; transition:background .2s; display:grid; grid-template-columns:1fr auto; gap:6px; }
    .note-item:hover { background:#1a2431; }
    .note-item .title { font-weight:600; }
    .note-item .meta { color:var(--muted); font-size:12px; margin-top:4px; }
    .pin { color:var(--warn); font-size:12px; }

    .tag { background:#16202c; border:1px solid var(--border); border-radius:999px; padding:5px 9px; margin:2px; display:inline-block; }
    .tag .x { color:#fca5a5; margin-left:5px; cursor:pointer; }

    .row { display:flex; gap:8px; align-items:center; }
    .grow { flex:1; }

    .attachment-list { display:flex; flex-direction:column; gap:6px; }
    .attachment-item { display:flex; justify-content:space-between; align-items:center; padding:8px 10px; border:1px solid var(--border); border-radius:10px; background:#10171f; font-size:13px; }
    .attachment-item a { color:var(--accent); text-decoration:none; }
    .attachment-item .meta { color:var(--muted); font-size:12px; }
    .muted { color:var(--muted); }

    #status { color:var(--muted); font-size:13px; }

    .toast { position:fixed; right:16px; bottom:16px; background:#0f1722; color:#e6edf7; border:1px solid #223046; padding:10px 12px; border-radius:10px; box-shadow:0 4px 16px #0007; opacity:0; transform:translateY(8px); transition:.2s; }
    .toast.show { opacity:1; transform:translateY(0); }

    @media (max-width:1100px) { .app { grid-template-columns:240px 1fr; grid-template-rows:1fr 1fr; } .notes{grid-column:2} .editor{grid-column:1 / span 2} }
    @media (max-width:700px) { .app { grid-template-columns:1fr; grid-template-rows:auto auto auto; } }
</style>
</head>
<body>
<div class="app">
    <!-- Sidebar: busca / cadernos / tags / filtros -->
    <section class="card sidebar">
        <div class="hdr"><h2>Navegação</h2></div>
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

    <!-- Lista de notas -->
    <section class="card notes">
        <div class="hdr"><h2>Notas</h2><button class="btn ok" id="btnNew" title="Nova nota">＋ Nova</button></div>
        <div class="body">
            <div id="noteList"></div>
        </div>
    </section>

    <!-- Editor -->
    <section class="card editor">
        <div class="hdr"><h2>Editor</h2></div>
        <div class="body">
            <div class="row">
                <input id="title" class="input grow" placeholder="Título" />
                <button class="btn" id="btnPin" title="Fixar">📌</button>
                <button class="btn" id="btnArchive" title="Arquivar">📦</button>
                <button class="btn danger" id="btnDelete" title="Excluir">🗑️</button>
            </div>
            <select id="notebook" class="input"></select>
            <textarea id="content" class="input" style="min-height:280px" placeholder="Escreva sua nota (Markdown opcional)"></textarea>
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Tags</div>
                <div id="tagBox"></div>
                <div class="row" style="margin-top:6px">
                    <input id="tagInput" class="input grow" placeholder="Digite e Enter" />
                    <button class="btn" id="btnClearTags" title="Limpar tags">Limpar</button>
                </div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Anexos</div>
                <div id="attachmentList" class="attachment-list"></div>
                <div class="row" style="margin-top:6px">
                    <button class="btn" id="btnSelectAttachment" type="button">📎 Adicionar anexos</button>
                    <div id="pendingUploads" class="muted" style="font-size:12px"></div>
                </div>
                <input type="file" id="attachmentUpload" multiple style="display:none" />
            </div>
        </div>
        <div class="footer">
            <button class="btn primary" id="btnSave">💾 Salvar (Ctrl/Cmd+S)</button>
            <div id="status">Pronto.</div>
        </div>
    </section>
</div>
<div id="toast" class="toast"></div>

<script>
// Utilitários de API
const api = (name, params={}) => {
  const url = new URL(location.href);
  url.searchParams.set('api', name);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  return fetch(url, {credentials:'same-origin'}).then(r => r.json());
};
const apiJSON = (name, body={}) => {
  const url = new URL(location.href);
  url.searchParams.set('api', name);
  return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}).then(r => r.json());
};
const apiForm = (name, formData) => {
  const url = new URL(location.href);
  url.searchParams.set('api', name);
  return fetch(url, {method:'POST', body: formData, credentials:'same-origin'}).then(r => r.json());
};

// Estado
let state = {
  filter: { q:'', notebook_id:null, tag:null, archived:false, pinned:false },
  notes: [], notebooks: [], tags: [],
  current: null,
  pendingUploads: [],
};

// Helpers de DOM
const el = sel => document.querySelector(sel);
const els = sel => Array.from(document.querySelectorAll(sel));
const ATTACH_HINT = 'Até 10 MB por arquivo.';
function toast(msg){ const t=el('#toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'), 1600); }
function setStatus(msg){ el('#status').textContent = msg; toast(msg); setTimeout(()=>{el('#status').textContent='Pronto.'}, 1600); }
function escapeHTML(s){ return (s+"").replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }
el('#pendingUploads').textContent = ATTACH_HINT;

// Carregar tudo
async function loadAll(){
  const [notebooks, tags] = await Promise.all([api('listNotebooks'), api('listTags')]);
  state.notebooks = notebooks; state.tags = tags; renderNotebooks(); renderTags();
  await refreshNotes();
}

async function refreshNotes(){
  const res = await api('listNotes', {
    q: state.filter.q || '',
    notebook_id: state.filter.notebook_id || '',
    tag: state.filter.tag || '',
    archived: state.filter.archived ? '1':'0',
    pinned: state.filter.pinned ? '1':'0',
  });
  state.notes = res; renderNoteList();
}

// Notebooks
function renderNotebooks(){
  const box = el('#notebooks'); box.innerHTML = '';
  const all = document.createElement('div'); all.className='pill'; all.textContent='Todos';
  all.onclick = ()=>{ state.filter.notebook_id=null; refreshNotes(); highlightNotebooks(); };
  box.appendChild(all);
  state.notebooks.forEach(nb => {
    const s = document.createElement('div'); s.className='pill'; s.textContent=nb.name; s.title='Clique para filtrar / Duplo clique para renomear';
    s.onclick = ()=>{ state.filter.notebook_id = nb.id; refreshNotes(); highlightNotebooks(); };
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
  els('#notebooks .pill').forEach((p,i)=>{ p.classList.toggle('active', i>0 && state.notebooks[i-1].id===id); });
}

// Tags
function renderTags(){
  const box = el('#tags'); box.innerHTML = '';
  state.tags.forEach(t => {
    const s = document.createElement('div'); s.className='pill'; s.textContent=t.name; s.onclick=()=>{ state.filter.tag = t.name; refreshNotes(); highlightTags(); };
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
      <div class="meta">${escapeHTML(n.tags||'')}</div>
    `;
    div.onclick = ()=> openNote(n.id);
    box.appendChild(div);
  });
}

// Editor
async function openNote(id){
  const n = await api('getNote', {id});
  state.current = n;
  state.current.attachments = n.attachments || [];
  el('#title').value = n.title || '';
  el('#content').value = n.content || '';
  el('#btnPin').dataset.pinned = n.pinned ? '1':'0';
  el('#btnArchive').dataset.archived = n.archived ? '1':'0';
  el('#notebook').value = n.notebook_id || '';
  renderTagBox(n.tags ? n.tags.split(',').filter(Boolean) : []);
  renderAttachmentList(state.current.attachments);
  el('#pendingUploads').textContent = ATTACH_HINT;
  return n;
}

function renderTagBox(tags){
  const box = el('#tagBox'); box.innerHTML = '';
  tags.forEach(t=>{
    const span = document.createElement('span'); span.className='tag';
    span.innerHTML = `${escapeHTML(t)} <span class="x" title="Remover">×</span>`;
    span.querySelector('.x').onclick=()=>{ 
      const arr = getEditorTags().filter(x=>x!==t); renderTagBox(arr);
    };
    box.appendChild(span);
  });
}
function getEditorTags(){
  return Array.from(el('#tagBox').querySelectorAll('.tag'))
    .map(x=>x.textContent.replace('×','').trim()).filter(Boolean);
}

function attachmentDownloadUrl(id){
  const url = new URL(location.origin + location.pathname);
  url.searchParams.set('attachment', id);
  return url.toString();
}

function formatBytes(bytes){
  const units = ['B','KB','MB','GB'];
  let size = bytes;
  let idx = 0;
  while(size >= 1024 && idx < units.length-1){ size /= 1024; idx++; }
  return `${size.toFixed(size >= 10 || idx === 0 ? 0 : 1)} ${units[idx]}`;
}

function renderAttachmentList(list){
  const box = el('#attachmentList');
  if(!list || !list.length){
    box.innerHTML = '<div class="muted" style="font-size:12px">Nenhum anexo.</div>';
    return;
  }
  box.innerHTML = '';
  list.forEach(att => {
    const row = document.createElement('div');
    row.className = 'attachment-item';
    const info = document.createElement('div');
    const link = document.createElement('a');
    link.href = attachmentDownloadUrl(att.id);
    link.textContent = att.name || 'arquivo';
    link.target = '_blank';
    link.rel = 'noopener';
    const meta = document.createElement('div');
    meta.className = 'meta';
    const details = [];
    if(att.created_at) details.push(att.created_at);
    if(typeof att.size === 'number') details.push(formatBytes(att.size));
    meta.textContent = details.join(' · ');
    info.appendChild(link);
    info.appendChild(meta);
    const remove = document.createElement('button');
    remove.className = 'btn danger';
    remove.type = 'button';
    remove.dataset.attachment = att.id;
    remove.textContent = 'Remover';
    row.appendChild(info);
    row.appendChild(remove);
    box.appendChild(row);
  });
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
  state.pendingUploads = [];
  el('#title').value='';
  el('#content').value='';
  el('#notebook').value='';
  el('#btnPin').dataset.pinned='0';
  el('#btnArchive').dataset.archived='0';
  renderTagBox([]);
  renderAttachmentList([]);
  el('#pendingUploads').textContent = ATTACH_HINT;
};

el('#btnSave').onclick = ()=>{ saveCurrent().catch(()=>{}); };
async function saveCurrent(opts={}){
  const silent = opts.silent || false;
  const payload = {
    id: state.current?.id || null,
    title: el('#title').value.trim(),
    content: el('#content').value,
    notebook_id: el('#notebook').value || null,
    tags: getEditorTags(),
  };
  const res = await apiJSON('saveNote', payload);
  if(res?.error){
    toast('Erro: ' + res.error);
    return null;
  }
  if(!silent) setStatus('Salvo.'); else el('#status').textContent='Pronto.';
  await refreshNotes();
  const savedId = res?.id || null;
  if(savedId) await openNote(savedId);
  return savedId;
}

el('#btnDelete').onclick = async ()=>{
  if(!state.current?.id) return;
  if(!confirm('Excluir permanentemente esta nota?')) return;
  await api('deleteNote', {id: state.current.id});
  setStatus('Excluída.');
  state.current=null; el('#title').value=''; el('#content').value=''; el('#notebook').value='';
  el('#btnPin').dataset.pinned='0';
  el('#btnArchive').dataset.archived='0';
  renderTagBox([]);
  renderAttachmentList([]);
  el('#pendingUploads').textContent = ATTACH_HINT;
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

const attachmentInput = el('#attachmentUpload');
el('#btnSelectAttachment').onclick = ()=> attachmentInput.click();
attachmentInput.addEventListener('change', handleAttachmentUpload);

async function handleAttachmentUpload(e){
  const files = Array.from(e.target.files || []);
  attachmentInput.value = '';
  if(!files.length) return;
  state.pendingUploads = files;
  el('#pendingUploads').textContent = `Enviando ${files.length} arquivo${files.length>1?'s':''}...`;
  try {
    let noteId = state.current?.id || null;
    if(!noteId){
      noteId = await saveCurrent({silent:true});
    }
    noteId = noteId || state.current?.id || null;
    if(!noteId){
      toast('Salve a nota antes de anexar arquivos.');
      return;
    }
    const form = new FormData();
    form.append('note_id', noteId);
    files.forEach(file => form.append('files[]', file));
    const res = await apiForm('uploadAttachment', form);
    if(res?.error){
      toast('Erro: ' + res.error);
      return;
    }
    if(Array.isArray(res)){
      state.current.attachments = [...(state.current.attachments || []), ...res];
      renderAttachmentList(state.current.attachments);
      setStatus('Anexo(s) enviados.');
    }
  } catch(err){
    console.error(err);
    toast('Erro ao enviar anexos.');
  } finally {
    state.pendingUploads = [];
    el('#pendingUploads').textContent = ATTACH_HINT;
  }
}

el('#attachmentList').addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-attachment]');
  if(!btn) return;
  const id = parseInt(btn.dataset.attachment, 10);
  if(!id || !state.current?.attachments) return;
  if(!confirm('Remover este anexo?')) return;
  const res = await apiJSON('deleteAttachment', {id});
  if(res?.error){ toast('Erro: ' + res.error); return; }
  state.current.attachments = state.current.attachments.filter(att => att.id !== id);
  renderAttachmentList(state.current.attachments);
  setStatus('Anexo removido.');
});

// Busca e Tags (editor)
el('#q').addEventListener('keydown', e=>{ if(e.key==='Enter'){ state.filter.q = el('#q').value.trim(); refreshNotes(); }});
el('#tagInput').addEventListener('keydown', e=>{
  if(e.key==='Enter'){
    e.preventDefault();
    const v = el('#tagInput').value.trim(); if(!v) return;
    const tags = Array.from(new Set([...getEditorTags(), v]));
    renderTagBox(tags); el('#tagInput').value='';
  }
});
el('#btnClearTags').onclick = ()=> renderTagBox([]);

// Filtros rápidos
els('.pill[data-filter]').forEach(p=> p.onclick = ()=>{
  const f = p.dataset.filter;
  state.filter.archived = f==='archived';
  state.filter.pinned   = f==='pinned';
  if(f==='all'){ state.filter.archived=false; state.filter.pinned=false; }
  refreshNotes();
  els('.pill[data-filter]').forEach(x=>x.classList.remove('active')); p.classList.add('active');
});

// Atalhos
window.addEventListener('keydown', (e)=>{
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s') { e.preventDefault(); saveCurrent(); }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k') { e.preventDefault(); el('#q').focus(); }
});

renderAttachmentList([]);
loadAll();
</script>
</body>
</html>
<?php
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime VARCHAR(120) NULL,
        size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
        INDEX(note_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
function tags_for_note(PDO $pdo, int $note_id): string {
    $st=$pdo->prepare("SELECT t.name FROM note_tags nt JOIN tags t ON t.id=nt.tag_id WHERE nt.note_id=? ORDER BY t.name");
    $st->execute([$note_id]);
    return implode(',', array_column($st->fetchAll(), 'name'));
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
// ANEXOS
//------------------------------------------------------------
function upload_attachments(PDO $pdo): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Método inválido');
    }
    $note_id = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
    if ($note_id <= 0) {
        throw new Exception('Nota inválida');
    }
    ensure_note_exists($pdo, $note_id);
    $files = normalize_files_array($_FILES['files'] ?? ($_FILES['file'] ?? []));
    if (!$files) {
        throw new Exception('Nenhum arquivo enviado');
    }
    $saved = [];
    foreach ($files as $file) {
        $saved[] = save_attachment($pdo, $note_id, $file);
    }
    return $saved;
}

function delete_attachment_api(PDO $pdo, array $data): array {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) {
        throw new Exception('Anexo inválido');
    }
    return delete_attachment($pdo, $id);
}

function normalize_files_array($spec): array {
    if (!$spec) return [];
    if (isset($spec['name']) && is_array($spec['name'])) {
        $files = [];
        foreach ($spec['name'] as $idx => $name) {
            $files[] = [
                'name' => $name,
                'type' => $spec['type'][$idx] ?? '',
                'tmp_name' => $spec['tmp_name'][$idx] ?? '',
                'error' => $spec['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                'size' => $spec['size'][$idx] ?? 0,
            ];
        }
        return array_values(array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    }
    if (isset($spec['name'])) {
        return [
            [
                'name' => $spec['name'],
                'type' => $spec['type'] ?? '',
                'tmp_name' => $spec['tmp_name'] ?? '',
                'error' => $spec['error'] ?? UPLOAD_ERR_NO_FILE,
                'size' => $spec['size'] ?? 0,
            ]
        ];
    }
    return [];
}

function ensure_note_exists(PDO $pdo, int $note_id): void {
    $st = $pdo->prepare('SELECT id FROM notes WHERE id=?');
    $st->execute([$note_id]);
    if (!$st->fetchColumn()) {
        throw new Exception('Nota não encontrada');
    }
}

function save_attachment(PDO $pdo, int $note_id, array $file): array {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        throw new Exception('Falha ao enviar arquivo');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new Exception('Arquivo vazio');
    }
    if ($size > MAX_ATTACHMENT_BYTES) {
        throw new Exception('Arquivo excede o limite de 10 MB');
    }
    $original = trim((string)($file['name'] ?? ''));
    $original = basename($original);
    $original = $original !== '' ? $original : 'anexo';
    if (function_exists('mb_substr')) {
        $original = mb_substr($original, 0, 200);
    } else {
        $original = substr($original, 0, 200);
    }

    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $safeExtSuffix = '';
    if ($ext !== '') {
        $safeExt = preg_replace('/[^A-Za-z0-9]/', '', $ext);
        if ($safeExt !== '') {
            $safeExtSuffix = '.' . strtolower($safeExt);
        }
    }

    $stored = bin2hex(random_bytes(16)) . $safeExtSuffix;
    $target = attachment_path($stored);
    while (file_exists($target)) {
        $stored = bin2hex(random_bytes(16)) . $safeExtSuffix;
        $target = attachment_path($stored);
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        throw new Exception('Upload inválido');
    }
    if (!move_uploaded_file($tmp, $target)) {
        throw new Exception('Falha ao salvar arquivo');
    }

    $mime = (string)($file['type'] ?? '');
    if ($mime === '' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($target);
        if ($detected) $mime = $detected;
    }

    $st = $pdo->prepare('INSERT INTO attachments(note_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?)');
    $st->execute([$note_id, $original, $stored, $mime, $size]);
    $id = (int)$pdo->lastInsertId();
    $row = fetch_attachment($pdo, $id);
    if (!$row) {
        throw new Exception('Erro ao registrar anexo');
    }
    return attachment_public($row);
}

function fetch_attachment(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT id, note_id, original_name, stored_name, mime, size, created_at FROM attachments WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function attachment_public(?array $row): array {
    if (!$row) return [];
    return [
        'id' => (int)$row['id'],
        'note_id' => (int)$row['note_id'],
        'name' => $row['original_name'],
        'mime' => $row['mime'],
        'size' => (int)$row['size'],
        'created_at' => date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')),
    ];
}

function list_attachments(PDO $pdo, int $note_id): array {
    $st = $pdo->prepare('SELECT id, note_id, original_name, stored_name, mime, size, created_at FROM attachments WHERE note_id=? ORDER BY created_at DESC');
    $st->execute([$note_id]);
    $rows = $st->fetchAll();
    return array_map('attachment_public', $rows);
}

function attachment_path(string $stored): string {
    return ATTACH_DIR . '/' . $stored;
}

function stream_attachment(PDO $pdo, int $id): void {
    $att = fetch_attachment($pdo, $id);
    if (!$att) {
        throw new Exception('Arquivo não encontrado');
    }
    $path = attachment_path($att['stored_name']);
    if (!is_file($path)) {
        throw new Exception('Arquivo não encontrado');
    }
    $name = $att['original_name'];
    $mime = $att['mime'] ?: 'application/octet-stream';
    $cleanName = preg_replace("/[\"\\\\]/", '', $name);
    $encoded = rawurlencode($name);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (int)$att['size']);
    header('Content-Disposition: attachment; filename="' . $cleanName . '"; filename*=UTF-8\'\'' . $encoded);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

function delete_attachment(PDO $pdo, int $id): array {
    $att = fetch_attachment($pdo, $id);
    if (!$att) {
        throw new Exception('Anexo não encontrado');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
    $path = attachment_path($att['stored_name']);
    if (is_file($path)) {
        @unlink($path);
    }
    return ['ok' => 1];
}

function attachment_files_for_note(PDO $pdo, int $note_id): array {
    $st = $pdo->prepare('SELECT stored_name FROM attachments WHERE note_id=?');
    $st->execute([$note_id]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

    $sql = "SELECT n.id, n.title, n.content, n.archived, n.pinned, n.notebook_id,
                   DATE_FORMAT(n.updated_at,'%Y-%m-%d %H:%i') AS updated_at,
                   (
                     SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
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
    $sql .= ' ORDER BY n.pinned DESC, n.updated_at DESC LIMIT 300';

    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}

function get_note(PDO $pdo, int $id): array {
    $st=$pdo->prepare("SELECT id, notebook_id, title, content, archived, pinned,
        DATE_FORMAT(updated_at,'%Y-%m-%d %H:%i') AS updated_at FROM notes WHERE id=?");
    $st->execute([$id]); $note = $st->fetch();
    if(!$note) throw new Exception('Nota não encontrada');
    $note['tags'] = tags_for_note($pdo, $id);
    $note['attachments'] = list_attachments($pdo, $id);
    return $note;
}

function save_note(PDO $pdo, array $data): array {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $title = trim((string)($data['title'] ?? ''));
    $content = (string)($data['content'] ?? '');
    $notebook_id = $data['notebook_id'] ? (int)$data['notebook_id'] : null;
    $tags = $data['tags'] ?? [];

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
    $files = attachment_files_for_note($pdo, $id);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM note_tags WHERE note_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
    $pdo->commit();
    foreach ($files as $stored) {
        $path = attachment_path($stored);
        if (is_file($path)) {
            @unlink($path);
        }
    }
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
