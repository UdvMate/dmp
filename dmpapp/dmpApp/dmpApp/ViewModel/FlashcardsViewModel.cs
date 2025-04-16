using System.Collections.ObjectModel;
using System.Threading.Tasks;
using System.Windows.Input;
using dmpApp.Data;
using Microsoft.Maui.Controls;

namespace dmpApp.ViewModel
{
    public class FlashcardsViewModel : BindableObject
    {
        private readonly DatabaseService _databaseService = new();
        private int _selectedFolderId;

        public ObservableCollection<Flashcard> Flashcards { get; set; } = new();
        public ObservableCollection<Folder> Folders { get; set; } = new();
        public ICommand LoadFlashcardsCommand { get; }

        public FlashcardsViewModel()
        {
            LoadFlashcardsCommand = new Command(async () => await LoadFlashcards());
            LoadFolders();
        }

        private async Task LoadFlashcards()
        {
            Flashcards.Clear();
            var flashcards = await _databaseService.GetFlashcardsAsync(_selectedFolderId);
            foreach (var flashcard in flashcards)
            {
                Flashcards.Add(flashcard);
            }
        }

        private void LoadFolders()
        {
            // Ezt az adatbázisból is lehetne tölteni
            Folders.Add(new Folder { Id = 1, Name = "Lesson 1" });
            Folders.Add(new Folder { Id = 2, Name = "Lesson 2" });
        }

        public void SelectFolder(int folderId)
        {
            _selectedFolderId = folderId;
            LoadFlashcardsCommand.Execute(null);
        }
    }

    public class Folder
    {
        public int Id { get; set; }
        public string Name { get; set; }
    }
}
