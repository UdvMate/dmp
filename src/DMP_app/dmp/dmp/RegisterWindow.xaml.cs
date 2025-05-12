using System;
using System.Net.Http;
using System.Text;
using System.Windows;
using Newtonsoft.Json;

namespace dmp
{
    public partial class RegisterWindow : Window
    {
        public RegisterWindow()
        {
            InitializeComponent();
        }

        private async void RegisterButton_Click(object sender, RoutedEventArgs e)
        {
            string username = UsernameTextBox.Text.Trim();
            string email = EmailTextBox.Text.Trim();
            string password = PasswordBox.Password.Trim();

            if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(email) || string.IsNullOrEmpty(password))
            {
                MessageBox.Show("Please fill in all fields.");
                return;
            }

            if (!IsValidEmail(email))
            {
                MessageBox.Show("Please enter a valid email address.");
                return;
            }

            var httpClient = new HttpClient();
            var payload = new
            {
                username = username,
                email = email,
                password = password // nem hash-eljük itt!
            };

            var jsonPayload = JsonConvert.SerializeObject(payload);
            var content = new StringContent(jsonPayload, Encoding.UTF8, "application/json");

            try
            {
                var response = await httpClient.PostAsync("http://localhost/dmp/src/DMP_web/API/registerApi.php", content);
                var responseBody = await response.Content.ReadAsStringAsync();

                MessageBox.Show(responseBody);
                this.Close();
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Registration failed: {ex.Message}");
            }
        }

        private bool IsValidEmail(string email)
        {
            try
            {
                var addr = new System.Net.Mail.MailAddress(email);
                return addr.Address == email;
            }
            catch
            {
                return false;
            }
        }
    }
}
