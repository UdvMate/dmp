using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
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
        private List<Set> allSets;
        private readonly HttpClient httpClient = new HttpClient();
        private readonly string setsApiUrl = "http://localhost/dmp/src/DMP_web/API/get_sets.php";

        public MainWindow(string username, int userId)
        {
            InitializeComponent();
            currentUsername = username;
            currentUserId = userId;
            UserInfoText.Text = $"Logged in as: {currentUsername}";
            PageNumberText.Text = currentPage.ToString();

            if (currentUsername != "Guest")
            {
                LoadSetsAsync();
            }
            else
            {
                ShowLoginMessage();
            }
        }

        public MainWindow() : this("Guest", -1) { }

        private async void LoadSetsAsync()
        {
            try
            {
                allSets = await GetSetsFromApi();
                DisplayPage(currentPage);
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Hiba a szettek betöltésekor: {ex.Message}");
            }
        }

        private async Task<List<Set>> GetSetsFromApi()
        {
            var postData = new { id = currentUserId };
            var content = new StringContent(JsonConvert.SerializeObject(postData), Encoding.UTF8, "application/json");
            HttpResponseMessage response = await httpClient.PostAsync(setsApiUrl, content);
            response.EnsureSuccessStatusCode();
            string responseBody = await response.Content.ReadAsStringAsync();
            var result = JsonConvert.DeserializeObject<SetResponse>(responseBody);
            return result.sets ?? new List<Set>();
        }

        private async Task DeleteSetAsync(int setId)
        {
            var result = MessageBox.Show("Biztosan törlöd ezt a szettet? Az összes kártya is törlődni fog!",
                "Figyelem", MessageBoxButton.YesNo, MessageBoxImage.Warning);

            if (result == MessageBoxResult.Yes)
            {
                try
                {
                    string url = $"http://localhost/dmp/src/DMP_web/API/delete_set.php?id={setId}";
                    HttpResponseMessage response = await httpClient.GetAsync(url);
                    response.EnsureSuccessStatusCode();
                    allSets = await GetSetsFromApi();
                    DisplayPage(currentPage);
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

            if (allSets == null || allSets.Count == 0)
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
            int endIndex = Math.Min(startIndex + cardsPerPage, allSets.Count);

            for (int i = startIndex; i < endIndex; i++)
            {
                Border card = CreateSetCard(allSets[i]);
                FlashcardsPanel.Children.Add(card);
            }

            PageNumberText.Text = currentPage.ToString();
        }

        private Border CreateSetCard(Set set)
        {
            Border card = new Border
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#0e1116")),
                CornerRadius = new CornerRadius(12),
                Padding = new Thickness(10),
                Margin = new Thickness(15),
                Width = 240,
                Height = 160,
                Effect = new DropShadowEffect
                {
                    Color = Colors.Black,
                    BlurRadius = 12,
                    ShadowDepth = 2,
                    Opacity = 0.4
                }
            };

            Grid cardGrid = new Grid();
            cardGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
            cardGrid.RowDefinitions.Add(new RowDefinition());

            // Szett címe
            TextBlock titleText = new TextBlock
            {
                Text = set.Title,
                FontSize = 16,
                FontWeight = FontWeights.Bold,
                Foreground = Brushes.White,
                Margin = new Thickness(0, 5, 0, 4),
                TextWrapping = TextWrapping.Wrap
            };
            Grid.SetRow(titleText, 0);
            cardGrid.Children.Add(titleText);

            // Létrehozási dátum
            TextBlock dateText = new TextBlock
            {
                Text = set.GeneratedAt.ToString("yyyy-MM-dd HH:mm"),
                FontSize = 12,
                Foreground = Brushes.Gray,
                Margin = new Thickness(0, 0, 0, 5),
                VerticalAlignment = VerticalAlignment.Bottom
            };
            Grid.SetRow(dateText, 1);
            cardGrid.Children.Add(dateText);

            // Törlés gomb
            Button moreButton = new Button
            {
                Content = "⋯",
                FontSize = 16,
                Width = 28,
                Height = 28,
                Background = Brushes.Transparent,
                Foreground = Brushes.White,
                HorizontalAlignment = HorizontalAlignment.Right
            };

            ContextMenu contextMenu = new ContextMenu();
            MenuItem deleteItem = new MenuItem { Header = "Delete" };
            deleteItem.Click += async (s, e) => await DeleteSetAsync(set.SetId);
            contextMenu.Items.Add(deleteItem);
            moreButton.ContextMenu = contextMenu;
            moreButton.Click += (s, e) => moreButton.ContextMenu.IsOpen = true;
            Grid.SetRow(moreButton, 0);
            cardGrid.Children.Add(moreButton);

            card.Child = cardGrid;
            return card;
        }

        private void ShowLoginMessage()
        {
            FlashcardsPanel.Children.Clear();
            FlashcardsPanel.Visibility = Visibility.Visible;
            NoCardsText.Visibility = Visibility.Collapsed;

            
            for (int i = 0; i < 3; i++)
            {
                if (i == 1)
                {
                    Grid centerGrid = new Grid
                    {
                        HorizontalAlignment = HorizontalAlignment.Stretch,
                        VerticalAlignment = VerticalAlignment.Stretch,
                        Height = 250
                    };
                    TextBlock message = new TextBlock
                    {
                        Text = "Log in to see the sets",
                        Foreground = Brushes.White,
                        FontSize = 18,
                        FontWeight = FontWeights.Bold,
                        HorizontalAlignment = HorizontalAlignment.Center,
                        VerticalAlignment = VerticalAlignment.Center,
                        TextAlignment = TextAlignment.Center
                    };
                    centerGrid.Children.Add(message);
                    FlashcardsPanel.Children.Add(centerGrid);
                }
                else
                {
                    FlashcardsPanel.Children.Add(new Grid { Height = 250 });
                }
            }
        }

        private void RefreshButton_Click(object sender, RoutedEventArgs e)
        {
            if (currentUsername != "Guest")
            {
                LoadSetsAsync();
            }
        }

        private void AccountButton_Click(object sender, RoutedEventArgs e)
        {
            AccountPopup.IsOpen = true;
        }

        private void Logout_Click(object sender, RoutedEventArgs e)
        {
            new LoginWindow().Show();
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
            int maxPage = (int)Math.Ceiling(allSets.Count / (double)cardsPerPage);
            if (currentPage < maxPage)
            {
                currentPage++;
                DisplayPage(currentPage);
            }
        }

        public class Set
        {
            public int SetId { get; set; }
            public string Title { get; set; }
            public DateTime GeneratedAt { get; set; }
        }

        public class SetResponse
        {
            public bool success { get; set; }
            public List<Set> sets { get; set; }
        }
    }
}
