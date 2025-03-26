using System.Data;
using System.Text;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Data;
using System.Windows.Documents;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Navigation;
using System.Windows.Shapes;
using MySql.Data.MySqlClient;

namespace dmp
{
    public partial class MainWindow : Window
    {
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;Password=;";
        private bool isUserLoggedIn = false; // Track login status

        public MainWindow()
        {
            InitializeComponent();


            // Check if user is logged in - initially set to false
            if (!isUserLoggedIn)
            {
                // Open login window
                LoginWindow loginWindow = new LoginWindow();
                loginWindow.ShowDialog(); // Modal dialog (blocks interaction with MainWindow)

                txtSearch.Text = "Enter text here...";

                // If the user closed LoginWindow without logging in, close the application
                if (!loginWindow.IsLoggedIn)
                {
                    this.Close();
                    return;
                }

                // If we get here, user logged in successfully
                isUserLoggedIn = true;
            }

            // Only connect to database and load data if user is logged in
            if (isUserLoggedIn)
            {
                VerifyDatabaseConnection();
                //LoadUserData();
            }
        }
        private string GetUsernameFromDatabase()
        {
            string query = "SELECT username FROM users WHERE Condition"; // Replace with your actual query

            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                connection.Open();

                MySqlCommand command = new MySqlCommand(query, connection);
                MySqlDataReader reader = command.ExecuteReader();

                if (reader.Read())
                {
                    return reader["Username"].ToString();
                }
                else
                {
                    return "Unknown User";
                }
            }
        }

        private void VerifyDatabaseConnection()
        {
            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();
                    // Consider removing or logging this message instead of showing it
                    // MessageBox.Show("Database connection successful!");
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Database connection failed: {ex.Message}");
                }
            }

            //USERNAME STUFF
            //string username = 
            //WelcomeBlock.Text = $"Welcome back, {username}";
        }

        

        private void txtSearch_GotFocus(object sender, RoutedEventArgs e)
        {
            if (txtSearch.Text == "Enter text here...")
            {
                txtSearch.Text = "";
            }
        }

        private void txtSearch_LostFocus(object sender, RoutedEventArgs e)
        {
            if (string.IsNullOrEmpty(txtSearch.Text))
            {
                txtSearch.Text = "Enter text here...";
            }
        }

        // Initialize the placeholder text when the window loads
        private void Window_Loaded(object sender, RoutedEventArgs e)
        {
            if (string.IsNullOrEmpty(txtSearch.Text))
            {
                txtSearch.Text = "Enter text here...";
            }
        }
    }
}