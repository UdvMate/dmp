namespace dmp.Models
{
    public class User
    {
        public int UserId { get; set; }      // Az adatbázisbeli 'id' oszlop
        public string Username { get; set; } // A felhasználó neve
        public string Email { get; set; }    // A felhasználó email címe
    }
}
