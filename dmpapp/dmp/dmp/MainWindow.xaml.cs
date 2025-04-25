using System;
using System.Windows;
using MySql.Data.MySqlClient;
using System.Collections.Generic;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media.Effects;
using System.Windows.Media;

namespace dmp
{
    public partial class MainWindow : Window
    {
        private string currentUsername;
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;Password=;";
        private int currentPage = 1;
        private int cardsPerPage = 6;
        private List<Flashcard> allFlashcards;

        public MainWindow(string username)
        {
            InitializeComponent();
            currentUsername = username;
            UserInfoText.Text = $"Logged in as: {currentUsername}";
            PageNumberText.Text = currentPage.ToString();

            if (currentUsername != "Vendég")
            {
                try
                {
                    allFlashcards = GetFlashcardsFromDatabase();
                    DisplayPage(currentPage);
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Hiba a kártyák betöltésekor: {ex.Message}");
                }
            }
            else
            {
                ShowLoginMessage();
            }
        }

        public MainWindow() : this("Vendég") { }

        private List<Flashcard> GetFlashcardsFromDatabase()
        {
            List<Flashcard> flashcards = new List<Flashcard>();
            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                connection.Open();
                string query = "SELECT flashcard_id, question, answer FROM flashcards";
                MySqlCommand command = new MySqlCommand(query, connection);
                MySqlDataReader reader = command.ExecuteReader();

                while (reader.Read())
                {
                    flashcards.Add(new Flashcard
                    {
                        Id = reader.GetInt32("flashcard_id"),
                        Question = reader.GetString("question"),
                        Answer = reader.GetString("answer")
                    });
                }
            }
            return flashcards;
        }

        private void DisplayPage(int pageNumber)
        {
            FlashcardsPanel.Children.Clear();

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

            // 2 sor: első a gomb, második a szöveg
            cardGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // gomb
            cardGrid.RowDefinitions.Add(new RowDefinition()); // tartalom (scroll)

            // Gomb
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
            deleteItem.Click += (s, e) => DeleteFlashcard(flashcard.Id);
            contextMenu.Items.Add(deleteItem);
            moreButton.ContextMenu = contextMenu;
            moreButton.Click += (s, e) => moreButton.ContextMenu.IsOpen = true;

            Grid.SetRow(moreButton, 0);
            cardGrid.Children.Add(moreButton);

            // Tartalom (scrollolható)
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



        private void DeleteFlashcard(int flashcardId)
        {
            var result = MessageBox.Show("Do you want to delete this card?", "Yes", MessageBoxButton.YesNo, MessageBoxImage.Warning);

            if (result == MessageBoxResult.Yes)
            {
                using (MySqlConnection connection = new MySqlConnection(connectionString))
                {
                    connection.Open();
                    string query = "DELETE FROM flashcards WHERE flashcard_id = @id";
                    MySqlCommand command = new MySqlCommand(query, connection);
                    command.Parameters.AddWithValue("@id", flashcardId);
                    command.ExecuteNonQuery();
                }

                allFlashcards = GetFlashcardsFromDatabase();
                int maxPage = (int)Math.Ceiling(allFlashcards.Count / (double)cardsPerPage);
                if (currentPage > maxPage) currentPage = maxPage;
                DisplayPage(currentPage);
            }
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
            if (currentUsername == "Vendég") return;
            if (currentPage > 1)
            {
                currentPage--;
                DisplayPage(currentPage);
            }
        }

        private void NextPage_Click(object sender, RoutedEventArgs e)
        {
            if (currentUsername == "Vendég") return;
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
}
