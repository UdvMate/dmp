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
                Padding = new Thickness(20),
                Margin = new Thickness(15),
                Width = 240,
                Height = 160,
                Effect = new DropShadowEffect { Color = Colors.Black, BlurRadius = 12, ShadowDepth = 2, Opacity = 0.4 }
            };

            StackPanel stackPanel = new StackPanel { Margin = new Thickness(0, 20, 0, 0) };

            Button moreButton = new Button
            {
                HorizontalAlignment = HorizontalAlignment.Right,
                VerticalAlignment = VerticalAlignment.Top,
                Content = "⋯",
                FontSize = 16,
                Width = 28,
                Height = 28,
                Background = Brushes.Transparent,
                Foreground = Brushes.White,
                BorderBrush = Brushes.Transparent,
                Cursor = Cursors.Hand
            };

            TextBlock questionText = new TextBlock
            {
                Text = flashcard.Question,
                FontSize = 16,
                FontWeight = FontWeights.Bold,
                Foreground = Brushes.White,
                Margin = new Thickness(0, 10, 0, 4)
            };

            TextBlock answerText = new TextBlock
            {
                Text = flashcard.Answer,
                FontSize = 12,
                Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#cbd5e1")),
                TextWrapping = TextWrapping.Wrap
            };

            stackPanel.Children.Add(moreButton);
            stackPanel.Children.Add(questionText);
            stackPanel.Children.Add(answerText);

            card.Child = stackPanel;
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
