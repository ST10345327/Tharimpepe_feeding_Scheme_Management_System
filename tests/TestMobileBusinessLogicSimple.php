<?php
/**
 * Simplified mobile business logic test cases that match actual model APIs
 */

require_once __DIR__ . "/../app/helpers/bootstrap.php";
require_once __DIR__ . "/TestCase.php";
require_once __DIR__ . "/../app/models/Beneficiary.php";
require_once __DIR__ . "/../app/models/Donation.php";
require_once __DIR__ . "/../app/models/Attendance.php";
require_once __DIR__ . "/../app/models/Volunteer.php";
require_once __DIR__ . "/../app/models/FoodStock.php";
require_once __DIR__ . "/../app/models/Dashboard.php";
require_once __DIR__ . "/../app/models/User.php";

class MobileBusinessLogicSimpleTest extends DatabaseTestCase
{
    private $beneficiaryModel;
    private $attendanceModel;
    private $donationModel;
    private $stockModel;
    private $volunteerModel;
    private $dashboardModel;
    private $userModel;
    
    public function setUp()
    {
        parent::setUp();
        $conn = $this->getConnection();
        $conn->exec("CREATE TABLE IF NOT EXISTS Users (
            UserID INTEGER PRIMARY KEY AUTOINCREMENT,
            Username TEXT UNIQUE NOT NULL,
            Email TEXT UNIQUE NOT NULL,
            PasswordHash TEXT NOT NULL,
            FullName TEXT,
            Phone TEXT,
            Role TEXT DEFAULT 'volunteer',
            Status TEXT DEFAULT 'pending'
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS Beneficiaries (
            BeneficiaryID INTEGER PRIMARY KEY AUTOINCREMENT,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Age INTEGER,
            Gender TEXT,
            Phone TEXT,
            Email TEXT,
            Address TEXT,
            RegistrationDate TEXT NOT NULL,
            Status TEXT DEFAULT 'active',
            Notes TEXT
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS MealSession (
            MealSessionID INTEGER PRIMARY KEY AUTOINCREMENT,
            SessionDate TEXT NOT NULL,
            SessionType TEXT NOT NULL,
            Location TEXT,
            Notes TEXT
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS Attendance (
            AttendanceID INTEGER PRIMARY KEY AUTOINCREMENT,
            BeneficiaryID INTEGER NOT NULL,
            MealSessionID INTEGER,
            SessionDate TEXT NOT NULL,
            Status TEXT DEFAULT 'present',
            Notes TEXT
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS FoodStock (
            StockID INTEGER PRIMARY KEY AUTOINCREMENT,
            ItemName TEXT NOT NULL,
            Quantity INTEGER NOT NULL,
            Unit TEXT NOT NULL,
            ExpiryDate TEXT,
            StockDate TEXT NOT NULL,
            Notes TEXT
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS Donations (
            DonationID INTEGER PRIMARY KEY AUTOINCREMENT,
            DonorName TEXT NOT NULL,
            DonorEmail TEXT,
            DonationType TEXT NOT NULL,
            Amount REAL,
            Description TEXT,
            DonationDate TEXT NOT NULL
        );");
        $conn->exec("CREATE TABLE IF NOT EXISTS Volunteers (
            VolunteerID INTEGER PRIMARY KEY AUTOINCREMENT,
            UserID INTEGER,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Phone TEXT,
            Address TEXT,
            AvailabilityStatus TEXT DEFAULT 'available'
        );");
        $this->beneficiaryModel = new Beneficiary($conn);
        $this->attendanceModel = new Attendance($conn);
        $this->donationModel = new Donation($conn);
        $this->stockModel = new FoodStock($conn);
        $this->volunteerModel = new Volunteer($conn);
        $this->dashboardModel = new Dashboard($conn);
        $this->userModel = new User($conn);
    }

    public function testBeneficiaryMobileWorkflow()
    {
        $beneficiaryId = $this->beneficiaryModel->createBeneficiary(
            "Jane", "Smith", date("Y-m-d"), 25, "Female", "0123456789", "jane@example.com", "Test Address", "Notes"
        );
        $this->assertNotNull($beneficiaryId, "Mobile should create beneficiary");
        $beneficiary = $this->beneficiaryModel->getBeneficiaryById($beneficiaryId);
        $this->assertNotNull($beneficiary);
        $this->assertEquals("Jane", $beneficiary["FirstName"]);
        $all = $this->beneficiaryModel->getAllBeneficiaries();
        $this->assertNotNull($all);
    }
}
