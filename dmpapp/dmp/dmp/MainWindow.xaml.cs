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
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;";

        public MainWindow()
        {
            InitializeComponent();
            VerifyDatabaseConnection();
            LoadUserTable();
        }

        private void VerifyDatabaseConnection()
        {
            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();
                    MessageBox.Show("Database connection successful!");
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Database connection failed: {ex.Message}");
                }
            }
        }

        private void LoadUserTable()
        {
            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();

                    // Query to fetch all data from the user table
                    string query = "SELECT * FROM users";

                    // Use MySqlDataAdapter to fill a DataTable
                    MySqlDataAdapter adapter = new MySqlDataAdapter(query, connection);
                    DataTable dataTable = new DataTable();
                    adapter.Fill(dataTable);

                    // Bind the DataTable to the DataGrid
                    UserDataGrid.ItemsSource = dataTable.DefaultView;
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Failed to load user table: {ex.Message}");
                }
            }
        }
    }
}