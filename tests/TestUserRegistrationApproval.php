<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../api/users.php';

class UserRegistrationApprovalTest extends DatabaseTestCase
{
    private $adminUserId;

    public function setUp()
    {
        parent::setUp();
        $this->getConnection()->exec("CREATE TABLE Volunteers (
            VolunteerID INTEGER PRIMARY KEY AUTOINCREMENT,
            UserID INTEGER NOT NULL UNIQUE,
            FirstName TEXT NOT NULL,
            LastName TEXT NOT NULL,
            Phone TEXT,
            Address TEXT,
            AvailabilityStatus TEXT DEFAULT 'unavailable',
            Status TEXT DEFAULT 'pending'
        )");
        $this->getConnection()->exec("CREATE TABLE ActivityLog (
            LogID INTEGER PRIMARY KEY AUTOINCREMENT,
            UserID INTEGER NOT NULL,
            Action TEXT NOT NULL,
            AffectedEntityName TEXT,
            AffectedEntityID INTEGER,
            Details TEXT,
            Timestamp TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $admin = $this->getConnection()->prepare("INSERT INTO Users (Username, Email, Role, Status) VALUES ('admin', 'admin@example.org', 'admin', 'active')");
        $admin->execute();
        $this->adminUserId = (int)$this->getConnection()->lastInsertId();
    }

    public function testApprovalActivatesUserAndVolunteer()
    {
        $userId = $this->createPendingUser('volunteer', 'Jordan Lee');
        $this->createVolunteer($userId, 'pending', 'unavailable');

        $result = transitionPendingRegistration($this->getConnection(), $userId, 'approve', $this->adminUserId);

        $this->assertEquals(['status' => 'active'], $result, 'Approval should activate the user');
        $user = $this->getConnection()->query("SELECT Status FROM Users WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $volunteer = $this->getConnection()->query("SELECT Status, AvailabilityStatus FROM Volunteers WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('active', $user['Status'], 'User status should be active');
        $this->assertEquals('approved', $volunteer['Status'], 'Volunteer profile should be approved');
        $this->assertEquals('available', $volunteer['AvailabilityStatus'], 'Approved volunteer should be available');
        $log = $this->getConnection()->query("SELECT UserID, Action FROM ActivityLog WHERE AffectedEntityID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals($this->adminUserId, (int)$log['UserID'], 'Approval should be attributed to the acting admin');
        $this->assertEquals('approve_user', $log['Action'], 'Approval should be audit logged');
    }

    public function testApprovalCreatesMissingVolunteerProfile()
    {
        $userId = $this->createPendingUser('volunteer', 'Jordan Lee');

        transitionPendingRegistration($this->getConnection(), $userId, 'approve', $this->adminUserId);

        $volunteer = $this->getConnection()->query("SELECT FirstName, LastName, Status, AvailabilityStatus FROM Volunteers WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Jordan', $volunteer['FirstName'], 'Volunteer first name should be derived from the account');
        $this->assertEquals('Lee', $volunteer['LastName'], 'Volunteer last name should be derived from the account');
        $this->assertEquals('approved', $volunteer['Status'], 'New volunteer profile should be approved');
        $this->assertEquals('available', $volunteer['AvailabilityStatus'], 'New approved volunteer should be available');
    }

    public function testRejectionDeactivatesUserAndVolunteer()
    {
        $userId = $this->createPendingUser('volunteer', 'Jordan Lee');
        $this->createVolunteer($userId, 'pending', 'unavailable');

        $result = transitionPendingRegistration($this->getConnection(), $userId, 'reject', $this->adminUserId);

        $this->assertEquals(['status' => 'inactive'], $result, 'Rejection should deactivate the user');
        $user = $this->getConnection()->query("SELECT Status FROM Users WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $volunteer = $this->getConnection()->query("SELECT Status, AvailabilityStatus FROM Volunteers WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('inactive', $user['Status'], 'Rejected user should be inactive');
        $this->assertEquals('inactive', $volunteer['Status'], 'Rejected volunteer profile should be inactive');
        $this->assertEquals('unavailable', $volunteer['AvailabilityStatus'], 'Rejected volunteer should remain unavailable');
        $log = $this->getConnection()->query("SELECT UserID, Action FROM ActivityLog WHERE AffectedEntityID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals($this->adminUserId, (int)$log['UserID'], 'Rejection should be attributed to the acting admin');
        $this->assertEquals('reject_user', $log['Action'], 'Rejection should be audit logged');
    }

    public function testAlreadyProcessedRegistrationCannotBeDecidedAgain()
    {
        $userId = $this->createPendingUser('donor', 'Taylor Doe');
        transitionPendingRegistration($this->getConnection(), $userId, 'approve', $this->adminUserId);

        $result = transitionPendingRegistration($this->getConnection(), $userId, 'reject', $this->adminUserId);

        $this->assertFalse($result, 'A non-pending registration should not be processed again');
        $user = $this->getConnection()->query("SELECT Status FROM Users WHERE UserID = $userId")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('active', $user['Status'], 'The original decision should remain unchanged');
    }

    private function createPendingUser($role, $fullName)
    {
        $stmt = $this->getConnection()->prepare("INSERT INTO Users (Username, Email, FullName, Role, Status) VALUES (:username, :email, :name, :role, 'pending')");
        $stmt->execute([
            ':username' => strtolower(str_replace(' ', '.', $fullName)),
            ':email' => strtolower(str_replace(' ', '.', $fullName)) . '@example.org',
            ':name' => $fullName,
            ':role' => $role,
        ]);
        return (int)$this->getConnection()->lastInsertId();
    }

    private function createVolunteer($userId, $status, $availability)
    {
        $stmt = $this->getConnection()->prepare('INSERT INTO Volunteers (UserID, FirstName, LastName, Status, AvailabilityStatus) VALUES (:user_id, :first_name, :last_name, :status, :availability)');
        $stmt->execute([
            ':user_id' => $userId,
            ':first_name' => 'Jordan',
            ':last_name' => 'Lee',
            ':status' => $status,
            ':availability' => $availability,
        ]);
    }
}
