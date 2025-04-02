using System;
using System.Threading.Tasks;
using Microsoft.Maui.Controls;
using dmpApp.Data;

namespace dmpApp.Views
{
    public partial class LoginPage : ContentPage
    {
        private readonly DatabaseService _databaseService;

        public LoginPage()
        {
            InitializeComponent();
            _databaseService = new DatabaseService();
        }

        private async void OnLoginClicked(object sender, EventArgs e)
        {
            string username = UsernameEntry.Text;
            string password = PasswordEntry.Text;

            if (string.IsNullOrWhiteSpace(username) || string.IsNullOrWhiteSpace(password))
            {
                await DisplayAlert("Hiba", "Felhasználónév és jelszó megadása kötelező!", "OK");
                return;
            }

            var user = await _databaseService.AuthenticateUser(username, password);
            if (user != null)
            {
                await DisplayAlert("Sikeres bejelentkezés", $"Üdvözöllek, {user.Username}!", "OK");
                await Shell.Current.GoToAsync("//MainPage");
            }
            else
            {
                await DisplayAlert("Hiba", "Érvénytelen felhasználónév vagy jelszó!", "OK");
            }
        }
    }
}