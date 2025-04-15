using dmpApp.ViewModel;
using Microsoft.Maui.Controls;

namespace dmpApp
{
    public partial class MainPage : ContentPage
    {
        private readonly FlashcardsViewModel _viewModel;

        public MainPage()
        {
            InitializeComponent();
            _viewModel = new FlashcardsViewModel();
            BindingContext = _viewModel;
        }

        private void OnFolderSelected(object sender, SelectionChangedEventArgs e)
        {
            if (e.CurrentSelection.FirstOrDefault() is Folder selectedFolder)
            {
                _viewModel.SelectFolder(selectedFolder.Id);
            }
        }

        private void OnShowAnswerClicked(object sender, EventArgs e)
        {
            if (sender is Button button && button.Parent is StackLayout stackLayout)
            {
                var answerLabel = stackLayout.Children.OfType<Label>().FirstOrDefault(l => l.Opacity == 0);
                if (answerLabel != null)
                {
                    answerLabel.Opacity = 1; // Felfedi a választ
                }
            }
        }
    }
}
