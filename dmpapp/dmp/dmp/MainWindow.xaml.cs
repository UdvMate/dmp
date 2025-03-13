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
        }

        /*private void LoadUserData()
        {
            // Your existing code to load user data
            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();
                    string query = "SELECT * FROM users";
                    MySqlDataAdapter adapter = new MySqlDataAdapter(query, connection);
                    DataTable dataTable = new DataTable();
                    adapter.Fill(dataTable);

                    // Assuming you have a DataGrid named UserDataGrid
                    UserDataGrid.ItemsSource = dataTable.DefaultView;
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Failed to load user data: {ex.Message}");
                }
            }
        }*/

        /*private string loggedInUsername;

        public MainWindow(string username)
        {
            InitializeComponent();
            loggedInUsername = username;

            // Update UI elements with the logged-in username
            WelcomeTextBlock.Text = $"Welcome back, {loggedInUsername}!";
            UsernameTextBlock.Text = loggedInUsername;
        }*/
    }
}