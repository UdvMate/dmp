using Microsoft.Maui.Controls;
using System.Security.Cryptography;
using System.Threading.Tasks;

namespace DMappFinal
{
    public partial class MainPage : ContentPage
    {
        private bool isSidebarExpanded = true;

        public MainPage()
        {
            InitializeComponent();
            LoadFlashcards();
        }

        private async void ToggleSidebar(object sender, EventArgs e)
        {
            isSidebarExpanded = !isSidebarExpanded;
            Sidebar.WidthRequest = isSidebarExpanded ? 250 : 50;

           
        }
        private async void LoadFlashcards()
        {
            var dbService = new DatabaseService();
            var flashcards = await dbService.GetFlashcardsAsync();

            foreach (var flashcard in flashcards)
            {
                Console.WriteLine(flashcard);  // Később megjelenítheted az UI-n is
            }
        }

    }
}
