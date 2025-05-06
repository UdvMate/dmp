using System;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using Newtonsoft.Json;

namespace dmp
{
    public partial class LoginWindow : Window
    {
        public bool IsLoggedIn { get; private set; } = false;

        public LoginWindow()
        {
            InitializeComponent();
        }

        private async void LoginButton_Click(object sender, RoutedEventArgs e)
        {
            string username = UsernameTextBox.Text.Trim();
            string password = PasswordBox.Password.Trim();

            if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
            {
                MessageBox.Show("Please enter both username and password.");
                return;
            }

            var httpClient = new HttpClient();
            var requestUrl = "http://localhost/dmp/loginApi.php";

            var payload = new
            {
                username = username,
                password = password
            };

            var jsonPayload = JsonConvert.SerializeObject(payload);
            var content = new StringContent(jsonPayload, Encoding.UTF8, "application/json");

            try
            {
                var response = await httpClient.PostAsync(requestUrl, content);
                var responseBody = await response.Content.ReadAsStringAsync();
                MessageBox.Show("Response: " + responseBody);

                var result = JsonConvert.DeserializeObject<LoginResult>(responseBody);

                if (result != null && result.success)
                {
                    IsLoggedIn = true;
                    MainWindow main = new MainWindow(username);
                    main.Show();
                    this.Close();
                }
                else
                {
                    MessageBox.Show("Login failed: " + result?.error ?? "Unknown error");
                }
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Login failed: {ex.Message}");
            }
        }

        private class LoginResult
        {
            public bool success { get; set; }
            public string error { get; set; }
        }

        private void RegisterButton_Click(object sender, RoutedEventArgs e)
        {
            RegisterWindow registerWindow = new RegisterWindow();
            registerWindow.ShowDialog();

            if (registerWindow.RegistrationSuccessful)
            {
                IsLoggedIn = true;
                this.Close();
            }
        }
    }
}
