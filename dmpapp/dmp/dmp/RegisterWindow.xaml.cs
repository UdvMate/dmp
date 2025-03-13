using System;
using System.Collections.Generic;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Data;
using System.Windows.Documents;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Shapes;
using MySql.Data.MySqlClient;

namespace dmp
{
    public partial class RegisterWindow : Window
    {
        private string connectionString = "Server=localhost;Database=dmproject;UserID=root;";
        public bool RegistrationSuccessful { get; private set; }

        public RegisterWindow()
        {
            InitializeComponent();
        }

        private string HashPassword(string password)
        {
            using (var sha256 = SHA256.Create())
            {
                byte[] bytes = sha256.ComputeHash(Encoding.UTF8.GetBytes(password));
                return Convert.ToBase64String(bytes);
            }
        }

        private void RegisterButton_Click(object sender, RoutedEventArgs e)
        {
            string username = UsernameTextBox.Text.Trim();
            string email = EmailTextBox.Text.Trim();
            string password = PasswordBox.Password.Trim();

            // Input validation
            if (string.IsNullOrWhiteSpace(username) ||
                string.IsNullOrWhiteSpace(email) ||
                string.IsNullOrWhiteSpace(password))
            {
                MessageBox.Show("Please fill in all fields.");
                return;
            }

            // Hash the password
            string hashedPassword = HashPassword(password);

            using (MySqlConnection connection = new MySqlConnection(connectionString))
            {
                try
                {
                    connection.Open();
                    string query = "INSERT INTO users (username, email, password) VALUES (@username, @email, @password)";

                    MySqlCommand command = new MySqlCommand(query, connection);
                    command.Parameters.AddWithValue("@username", username);
                    command.Parameters.AddWithValue("@email", email);
                    command.Parameters.AddWithValue("@password", hashedPassword);

                    int result = command.ExecuteNonQuery();
                    if (result > 0)
                    {
                        RegistrationSuccessful = true;
                        MessageBox.Show("Registration successful!");
                        this.Close();
                    }
                }
                catch (MySqlException ex)
                {
                    // Handle duplicate username/email errors
                    if (ex.Number == 1062)
                    {
                        MessageBox.Show("Username or email already exists.");
                    }
                    else
                    {
                        MessageBox.Show($"Database error: {ex.Message}");
                    }
                }
                catch (Exception ex)
                {
                    MessageBox.Show($"Error: {ex.Message}");
                }
            }
        }
    }
}
