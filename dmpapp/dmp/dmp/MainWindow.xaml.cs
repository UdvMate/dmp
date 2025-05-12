using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Effects;
using Newtonsoft.Json;

namespace dmp
{
    public partial class MainWindow : Window
    {
        private string currentUsername;
        private int currentUserId;
        private int currentPage = 1;
        private int cardsPerPage = 6;
        private List<Flashcard> allFlashcards;
        private readonly HttpClient httpClient = new HttpClient();
        private readonly string apiBaseUrl = "http://localhost/dmp/get_flashcards.php";

        public MainWindow(string username, int userId)
        {
            InitializeComponent();
            currentUsername = username;
            currentUserId = userId;
            UserInfoText.Text = $"Logged in as: {currentUsername}";
            PageNumberText.Text = currentPage.ToString();

            if (currentUsername != "Guest")
            {
                LoadFlashcardsAsync();
            }
            else
            {
                ShowLoginMessage();
            }
        }

        public MainWindow() : this("Guest", -1) { }

        private async void LoadFlashcardsAsync()
        {
            try
            {
                allFlashcards = await GetFlashcardsFromApi();
                DisplayPage(currentPage);
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Hiba a kártyák betöltésekor: {ex.Message}");
            }
        }

        private async Task<List<Flashcard>> GetFlashcardsFromApi()
        {
            var postData = new { id = currentUserId };
            var content = new StringContent(JsonConvert.SerializeObject(postData), Encoding.UTF8, "application/json");

            HttpResponseMessage response = await httpClient.PostAsync(apiBaseUrl, content);
            response.EnsureSuccessStatusCode();

            string responseBody = await response.Content.ReadAsStringAsync();
            var result = JsonConvert.DeserializeObject<FlashcardResponse>(responseBody);

            return result.flashcards ?? new List<Flashcard>();
        }

        private async Task DeleteFlashcardAsync(int flashcardId)
        {
            var result = MessageBox.Show("Do you want to delete this card?", "Warning", MessageBoxButton.YesNo, MessageBoxImage.Warning);

            if (result == MessageBoxResult.Yes)
            {
                try
                {
                    string url = $"http://localhost/dmp/delete_flashcard.php?id={flashcardId}";
                    HttpResponseMessage response = await httpClient.GetAsync(url);
                    response.EnsureSuccessStatusCode();

                    string responseBody = await response.Content.ReadAsStringAsync();
                    var resultJson = JsonConvert.DeserializeObject<Dictionary<string, string>>(responseBody);

                    if (resultJson.ContainsKey("success"))
                    {
                        MessageBox.Show("Kártya sikeresen törölve!");
                        allFlashcards = await GetFlashcardsFromApi();
                        int maxPage = (int)Math.Ceiling(allFlashcards.Count / (double)cardsPerPage);
                        if (currentPage > maxPage) currentPage = maxPage;
                        DisplayPage(currentPage);
                    }
                    else if (resultJson.ContainsKey("error"))
                    {
                        MessageBox.Show("Hiba: " + resultJson["error"]);
                    }
                }
                catch (Exception ex)
                {
                    MessageBox.Show("Hiba a törlés közben: " + ex.Message);
                }
            }
        }

        private void DisplayPage(int pageNumber)
        {
            FlashcardsPanel.Children.Clear();

            if (allFlashcards == null || allFlashcards.Count == 0)
            {
                NoCardsText.Visibility = Visibility.Visible;
                FlashcardsPanel.Visibility = Visibility.Collapsed;
                PageNumberText.Text = "1";
                return;
            }
            else
            {
                NoCardsText.Visibility = Visibility.Collapsed;
                FlashcardsPanel.Visibility = Visibility.Visible;
            }

            int startIndex = (pageNumber - 1) * cardsPerPage;
            int endIndex = Math.Min(startIndex + cardsPerPage, allFlashcards.Count);

            for (int i = startIndex; i < endIndex; i++)
            {
                Border card = CreateFlashcard(allFlashcards[i]);
                FlashcardsPanel.Children.Add(card);
            }

            PageNumberText.Text = currentPage.ToString();
        }



        private Border CreateFlashcard(Flashcard flashcard)
        {
            Border card = new Border
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#0e1116")),
                CornerRadius = new CornerRadius(12),
                Padding = new Thickness(10),
                Margin = new Thickness(15),
                Width = 240,
                Height = 160,
                Effect = new DropShadowEffect { Color = Colors.Black, BlurRadius = 12, ShadowDepth = 2, Opacity = 0.4 }
            };

            Grid cardGrid = new Grid();
            cardGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
            cardGrid.RowDefinitions.Add(new RowDefinition());

            Button moreButton = new Button
            {
                Content = "⋯",
                FontSize = 16,
                Width = 28,
                Height = 28,
                Background = Brushes.Transparent,
                Foreground = Brushes.White,
                BorderBrush = Brushes.Transparent,
                Cursor = Cursors.Hand,
                HorizontalAlignment = HorizontalAlignment.Right,
                VerticalAlignment = VerticalAlignment.Top,
                Margin = new Thickness(0, 0, 0, 5)
            };

            ContextMenu contextMenu = new ContextMenu();
            MenuItem deleteItem = new MenuItem { Header = "Delete" };
            deleteItem.Click += async (s, e) => await DeleteFlashcardAsync(flashcard.Id);
            contextMenu.Items.Add(deleteItem);
            moreButton.ContextMenu = contextMenu;
            moreButton.Click += (s, e) => moreButton.ContextMenu.IsOpen = true;

            Grid.SetRow(moreButton, 0);
            cardGrid.Children.Add(moreButton);

            ScrollViewer scrollViewer = new ScrollViewer
            {
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
                Margin = new Thickness(0, 5, 0, 0)
            };

            StackPanel contentPanel = new StackPanel();

            TextBlock questionText = new TextBlock
            {
                Text = flashcard.Question,
                FontSize = 16,
                FontWeight = FontWeights.Bold,
                Foreground = Brushes.White,
                Margin = new Thickness(0, 5, 0, 4),
                TextWrapping = TextWrapping.Wrap
            };

            TextBlock answerText = new TextBlock
            {
                Text = flashcard.Answer,
                FontSize = 12,
                Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#cbd5e1")),
                TextWrapping = TextWrapping.Wrap
            };

            contentPanel.Children.Add(questionText);
            contentPanel.Children.Add(answerText);

            scrollViewer.Content = contentPanel;

            Grid.SetRow(scrollViewer, 1);
            cardGrid.Children.Add(scrollViewer);

            card.Child = cardGrid;
            return card;
        }

        private void ShowLoginMessage()
        {
            FlashcardsPanel.Children.Clear();
            TextBlock message = new TextBlock
            {
                Text = "Log in to see the cards",
                Foreground = Brushes.White,
                FontSize = 18,
                FontWeight = FontWeights.Bold,
                HorizontalAlignment = HorizontalAlignment.Center,
                VerticalAlignment = VerticalAlignment.Center
            };

            FlashcardsPanel.Children.Add(message);
        }

        private void AccountButton_Click(object sender, RoutedEventArgs e)
        {
            AccountPopup.IsOpen = true;
        }

        private void Logout_Click(object sender, RoutedEventArgs e)
        {
            var loginWindow = new LoginWindow();
            loginWindow.Show();
            this.Close();
        }

        private void PrevPage_Click(object sender, RoutedEventArgs e)
        {
            if (currentUsername == "Guest") return;
            if (currentPage > 1)
            {
                currentPage--;
                DisplayPage(currentPage);
            }
        }

        private void NextPage_Click(object sender, RoutedEventArgs e)
        {
            if (currentUsername == "Guest") return;
            int maxPage = (int)Math.Ceiling(allFlashcards.Count / (double)cardsPerPage);
            if (currentPage < maxPage)
            {
                currentPage++;
                DisplayPage(currentPage);
            }
        }
    }

    public class Flashcard
    {
        public int Id { get; set; }
        public string Question { get; set; }
        public string Answer { get; set; }
    }

    public class FlashcardResponse
    {
        public bool success { get; set; }
        public List<Flashcard> flashcards { get; set; }
    }
}
