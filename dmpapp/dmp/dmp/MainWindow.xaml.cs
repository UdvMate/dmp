using System;
using System.Windows;
using MySql.Data.MySqlClient;
using System.Collections.Generic;
using System.Windows.Controls;

namespace dmp
{
    public partial class MainWindow : Window
    {
        private string currentUsername;
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;Password=;";

        public MainWindow(string username)
        {
            InitializeComponent();
            currentUsername = username;
            UserInfoText.Text = $"Logged in as: {currentUsername}";
            try
            {
                LoadFlashcards();
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Hiba a kártyák betöltésekor: {ex.Message}");
            }
        }
        public MainWindow() : this("Guest")
        {
        }


        // Account button click event
        private void AccountButton_Click(object sender, RoutedEventArgs e)
        {
            AccountPopup.IsOpen = true; // Popup megjelenítése
        }

        // Logout button click event
        private void Logout_Click(object sender, RoutedEventArgs e)
        {
            var loginWindow = new LoginWindow();
            loginWindow.Show();
            this.Close();
        }

        // Page navigation button click event
        private void PageButton_Click(object sender, RoutedEventArgs e)
        {
            Button clickedButton = sender as Button;
            if (clickedButton != null)
            {
                int pageNumber = Convert.ToInt32(clickedButton.Tag);
                MessageBox.Show($"Navigating to page {pageNumber}");
                // Itt dolgozhatsz a lapozás logikájával, például:
                // LoadFlashcards(pageNumber); - ha szükséges a kártyák betöltése a kiválasztott oldalon
            }
        }

        // Flashcards loading logic
        private void LoadFlashcards()
        {
            List<Flashcard> flashcards = GetFlashcardsFromDatabase();
            foreach (var flashcard in flashcards)
            {
                // Dynamically create cards for each flashcard in the database
                Border card = CreateFlashcard(flashcard);
                FlashcardsPanel.Children.Add(card);  // Add to the main panel
            }
        }

        // Fetch flashcards from the database
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

        // Create a single flashcard UI element
        private Border CreateFlashcard(Flashcard flashcard)
        {
            Border card = new Border
            {
                Background = new System.Windows.Media.SolidColorBrush((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString("#0e1116")),
                CornerRadius = new System.Windows.CornerRadius(12),
                Padding = new System.Windows.Thickness(20),
                Margin = new System.Windows.Thickness(15),
                Width = 240,
                Height = 160,
                Effect = new System.Windows.Media.Effects.DropShadowEffect { Color = System.Windows.Media.Colors.Black, BlurRadius = 12, ShadowDepth = 2, Opacity = 0.4 }
            };

            StackPanel stackPanel = new StackPanel { Margin = new System.Windows.Thickness(0, 20, 0, 0) };

            Button moreButton = new Button
            {
                Content = "⋯",
                FontSize = 16,
                Width = 28,
                Height = 28,
                Background = System.Windows.Media.Brushes.Transparent,
                Foreground = System.Windows.Media.Brushes.White,
                BorderBrush = System.Windows.Media.Brushes.Transparent,
                Cursor = System.Windows.Input.Cursors.Hand
            };

            TextBlock questionText = new TextBlock
            {
                Text = flashcard.Question,
                FontSize = 16,
                FontWeight = System.Windows.FontWeights.Bold,
                Foreground = System.Windows.Media.Brushes.White,
                Margin = new System.Windows.Thickness(0, 10, 0, 4)
            };

            TextBlock answerText = new TextBlock
            {
                Text = flashcard.Answer,
                FontSize = 12,
                Foreground = new System.Windows.Media.SolidColorBrush((System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString("#cbd5e1")),
                TextWrapping = TextWrapping.Wrap
            };

            stackPanel.Children.Add(moreButton);
            stackPanel.Children.Add(questionText);
            stackPanel.Children.Add(answerText);

            card.Child = stackPanel;
            return card;
        }
    }

    public class Flashcard
    {
        public int Id { get; set; }
        public string Question { get; set; }
        public string Answer { get; set; }
    }
}
