using System;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using Newtonsoft.Json;

namespace dmp
{
    public partial class RegisterWindow : Window
    {
        public bool RegistrationSuccessful { get; private set; } = false;

        public RegisterWindow()
        {
            InitializeComponent();
        }

        private async void RegisterButton_Click(object sender, RoutedEventArgs e)
        {
            string username = UsernameTextBox.Text.Trim();
            string password = PasswordBox.Password.Trim();

            if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
            {
                MessageBox.Show("Please enter both username and password.");
                return;
            }

            var httpClient = new HttpClient();
            var requestUrl = "http://localhost/dmp/registerApi.php"; // helyi útvonalad

            var jsonPayload = JsonConvert.SerializeObject(new { username, password });
            var content = new StringContent(jsonPayload, Encoding.UTF8, "application/json");

            try
            {
                var response = await httpClient.PostAsync(requestUrl, content);
                var responseBody = await response.Content.ReadAsStringAsync();

                var result = JsonConvert.DeserializeObject<RegisterResult>(responseBody);


                if (result != null && result.success)
                {
                    MessageBox.Show("Registration successful!");
                    RegistrationSuccessful = true;
                    this.Close();
                }
                else
                {
                    MessageBox.Show(result?.error ?? "Registration failed.");

                }

            }
            catch (Exception ex)
            {
                

                MessageBox.Show($"Registration error: {ex.Message}");
            }
        }


        private class RegisterResult
        {
            public bool success { get; set; }
            public string error { get; set; }
        }
    }
}
