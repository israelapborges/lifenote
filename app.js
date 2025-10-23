// LifeNote Application
class LifeNote {
    constructor() {
        this.notes = this.loadNotes();
        this.currentNoteId = null;
        this.initializeElements();
        this.attachEventListeners();
        this.render();
    }

    initializeElements() {
        this.notesList = document.getElementById('notesList');
        this.noteModal = document.getElementById('noteModal');
        this.newNoteBtn = document.getElementById('newNoteBtn');
        this.closeModalBtn = document.getElementById('closeModal');
        this.cancelBtn = document.getElementById('cancelBtn');
        this.saveNoteBtn = document.getElementById('saveNoteBtn');
        this.deleteNoteBtn = document.getElementById('deleteNoteBtn');
        this.modalTitle = document.getElementById('modalTitle');
        this.noteTitle = document.getElementById('noteTitle');
        this.noteContent = document.getElementById('noteContent');
    }

    attachEventListeners() {
        this.newNoteBtn.addEventListener('click', () => this.openNewNote());
        this.closeModalBtn.addEventListener('click', () => this.closeModal());
        this.cancelBtn.addEventListener('click', () => this.closeModal());
        this.saveNoteBtn.addEventListener('click', () => this.saveNote());
        this.deleteNoteBtn.addEventListener('click', () => this.deleteNote());
        
        // Close modal when clicking outside
        this.noteModal.addEventListener('click', (e) => {
            if (e.target === this.noteModal) {
                this.closeModal();
            }
        });

        // Handle Enter key in title field
        this.noteTitle.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.noteContent.focus();
            }
        });
    }

    loadNotes() {
        const notes = localStorage.getItem('lifenotes');
        return notes ? JSON.parse(notes) : [];
    }

    saveNotes() {
        localStorage.setItem('lifenotes', JSON.stringify(this.notes));
    }

    openNewNote() {
        this.currentNoteId = null;
        this.modalTitle.textContent = 'Nova Anotação';
        this.noteTitle.value = '';
        this.noteContent.value = '';
        this.deleteNoteBtn.style.display = 'none';
        this.openModal();
    }

    openEditNote(id) {
        const note = this.notes.find(n => n.id === id);
        if (!note) return;

        this.currentNoteId = id;
        this.modalTitle.textContent = 'Editar Anotação';
        this.noteTitle.value = note.title;
        this.noteContent.value = note.content;
        this.deleteNoteBtn.style.display = 'block';
        this.openModal();
    }

    openModal() {
        this.noteModal.classList.add('active');
        this.noteTitle.focus();
    }

    closeModal() {
        this.noteModal.classList.remove('active');
        this.currentNoteId = null;
    }

    saveNote() {
        const title = this.noteTitle.value.trim();
        const content = this.noteContent.value.trim();

        if (!title && !content) {
            alert('Por favor, adicione um título ou conteúdo para sua anotação.');
            return;
        }

        if (this.currentNoteId) {
            // Update existing note
            const note = this.notes.find(n => n.id === this.currentNoteId);
            if (note) {
                note.title = title || 'Sem título';
                note.content = content;
                note.updatedAt = new Date().toISOString();
            }
        } else {
            // Create new note
            const newNote = {
                id: Date.now(),
                title: title || 'Sem título',
                content: content,
                createdAt: new Date().toISOString(),
                updatedAt: new Date().toISOString()
            };
            this.notes.unshift(newNote);
        }

        this.saveNotes();
        this.render();
        this.closeModal();
    }

    deleteNote() {
        if (!this.currentNoteId) return;

        if (confirm('Tem certeza que deseja excluir esta anotação?')) {
            this.notes = this.notes.filter(n => n.id !== this.currentNoteId);
            this.saveNotes();
            this.render();
            this.closeModal();
        }
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = Math.abs(now - date);
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));

        if (diffDays === 0) {
            return 'Hoje';
        } else if (diffDays === 1) {
            return 'Ontem';
        } else if (diffDays < 7) {
            return `${diffDays} dias atrás`;
        } else {
            return date.toLocaleDateString('pt-BR');
        }
    }

    render() {
        if (this.notes.length === 0) {
            this.notesList.innerHTML = `
                <div class="empty-state" style="grid-column: 1/-1;">
                    <h2>📝 Nenhuma anotação ainda</h2>
                    <p>Clique em "Nova Anotação" para começar</p>
                </div>
            `;
            return;
        }

        this.notesList.innerHTML = this.notes.map(note => `
            <div class="note-card" data-id="${note.id}">
                <h3>${this.escapeHtml(note.title)}</h3>
                <p>${this.escapeHtml(note.content)}</p>
                <div class="note-date">${this.formatDate(note.updatedAt)}</div>
            </div>
        `).join('');

        // Add click listeners to note cards
        document.querySelectorAll('.note-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = parseInt(card.dataset.id);
                this.openEditNote(id);
            });
        });
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize the application when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new LifeNote();
});
