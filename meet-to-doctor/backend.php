<?php
session_start();
header("Content-Type: application/json");

/**
 * Meet to Doctor: Patient Care — Backend (backend.php)
 * Full backend: DB schema, session management, CRUD operations
 * Stack: PHP + MySQL (XAMPP/WAMP)
 * Usage: Include this file in any page, then call the relevant function.
 */

// ─── DATABASE CONFIGURATION ───────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change to your MySQL user
define('DB_PASS', '');              // Change to your MySQL password
define('DB_NAME', 'meet_to_doctor');

// ─── DATABASE CONNECTION ──────────────────────────────────────────────────────
function getDB(): mysqli
{
    static $db = null;
    if ($db === null) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'DB connection failed: ' . $db->connect_error]));
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

// ─── DATABASE SETUP (run once) ────────────────────────────────────────────────
function setupDatabase(): void
{
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $db->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db(DB_NAME);

    $tables = [
        // Department
        "CREATE TABLE IF NOT EXISTS `department` (
            `department_id`   INT AUTO_INCREMENT PRIMARY KEY,
            `department_name` VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB",

        // Admin
        "CREATE TABLE IF NOT EXISTS `admin` (
            `admin_id`  INT AUTO_INCREMENT PRIMARY KEY,
            `name`      VARCHAR(100) NOT NULL,
            `email`     VARCHAR(150) NOT NULL UNIQUE,
            `password`  VARCHAR(255) NOT NULL,
            `role`      ENUM('superadmin','admin') DEFAULT 'admin',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        // Doctor
        "CREATE TABLE IF NOT EXISTS `doctor` (
            `doctor_id`      INT AUTO_INCREMENT PRIMARY KEY,
            `department_id`  INT NOT NULL,
            `name`           VARCHAR(100) NOT NULL,
            `email`          VARCHAR(150) NOT NULL UNIQUE,
            `phone`          VARCHAR(20),
            `password`       VARCHAR(255) NOT NULL,
            `specialization` VARCHAR(150),
            `fee`            DECIMAL(10,2) DEFAULT 500.00,
            `photo`          VARCHAR(255) DEFAULT 'default_doctor.png',
            `status`         ENUM('active','inactive') DEFAULT 'active',
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`department_id`) REFERENCES `department`(`department_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB",

        // Doctor Availability
        "CREATE TABLE IF NOT EXISTS `doctor_availability` (
            `availability_id` INT AUTO_INCREMENT PRIMARY KEY,
            `doctor_id`       INT NOT NULL,
            `day_of_week`     ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
            `start_time`      TIME NOT NULL,
            `end_time`        TIME NOT NULL,
            `is_available`    TINYINT(1) DEFAULT 1,
            FOREIGN KEY (`doctor_id`) REFERENCES `doctor`(`doctor_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB",

        // Patient
        "CREATE TABLE IF NOT EXISTS `patient` (
            `patient_id`  INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(100) NOT NULL,
            `email`       VARCHAR(150) NOT NULL UNIQUE,
            `phone`       VARCHAR(20),
            `password`    VARCHAR(255) NOT NULL,
            `gender`      ENUM('Male','Female','Other'),
            `age`         INT,
            `address`     TEXT,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        // Patient Availability
        "CREATE TABLE IF NOT EXISTS `patient_availability` (
            `aviablity_id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id`   INT NOT NULL,
            `start_time`   TIME NOT NULL,
            `end_time`     TIME NOT NULL,
            `status`       ENUM('available','busy') DEFAULT 'available',
            FOREIGN KEY (`patient_id`) REFERENCES `patient`(`patient_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB",

        // Appointment
        "CREATE TABLE IF NOT EXISTS `appointment` (
            `appointment_id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id`     INT NOT NULL,
            `doctor_id`      INT NOT NULL,
            `date`           DATE NOT NULL,
            `time`           TIME NOT NULL,
            `status`         ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
            `notes`          TEXT,
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patient`(`patient_id`) ON DELETE CASCADE,
            FOREIGN KEY (`doctor_id`)  REFERENCES `doctor`(`doctor_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB",

        // Billing
        "CREATE TABLE IF NOT EXISTS `billing` (
            `bill_id`        INT AUTO_INCREMENT PRIMARY KEY,
            `appointment_id` INT NOT NULL UNIQUE,
            `patient_id`     INT NOT NULL,
            `amount`         DECIMAL(10,2) NOT NULL,
            `status`         ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',
            `date`           DATE NOT NULL,
            FOREIGN KEY (`appointment_id`) REFERENCES `appointment`(`appointment_id`) ON DELETE CASCADE,
            FOREIGN KEY (`patient_id`)     REFERENCES `patient`(`patient_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB",

        // Medicine
        "CREATE TABLE IF NOT EXISTS `medicine` (
            `medicine_id`    INT AUTO_INCREMENT PRIMARY KEY,
            `appointment_id` INT,
            `patient_id`     INT NOT NULL,
            `name`           VARCHAR(150) NOT NULL,
            `quality`        VARCHAR(100),
            `expire_date`    DATE,
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`appointment_id`) REFERENCES `appointment`(`appointment_id`) ON DELETE SET NULL,
            FOREIGN KEY (`patient_id`)     REFERENCES `patient`(`patient_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    ];

    foreach ($tables as $sql) {
        $db->query($sql);
    }

    // Seed default admin if none exists
    $res = $db->query("SELECT COUNT(*) as c FROM `admin`");
    if ($res && $res->fetch_assoc()['c'] == 0) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $db->query("INSERT INTO `admin` (name, email, password, role) VALUES ('Super Admin','admin@doctime.com','$hash','superadmin')");
    }

    // Seed sample departments
    $depts = ['Cardiology', 'Neurology', 'Orthopedics', 'Dermatology', 'Pediatrics', 'Gynecology', 'ENT', 'General Medicine'];
    foreach ($depts as $d) {
        $db->query("INSERT IGNORE INTO `department` (department_name) VALUES ('$d')");
    }

    $db->close();
    echo json_encode(['success' => true, 'message' => 'Database setup complete.']);
    exit;
}

// ─── SESSION HELPERS ──────────────────────────────────────────────────────────
function startSession(): void
{
    // session already started at top of file
}

function currentUser(): ?array
{
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireRole(string $role): void
{
    $user = currentUser();
    if (!$user || $user['role'] !== $role) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Access denied.']));
    }
}

function jsonOut(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─── AUTH ─────────────────────────────────────────────────────────────────────

/** POST /backend.php?action=register_patient */
function registerPatient(): void
{
    $db = getDB();
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $gender  = $_POST['gender'] ?? 'Other';
    $age     = (int)($_POST['age'] ?? 0);
    $address = trim($_POST['address'] ?? '');

    if (!$name || !$email || !$pass) jsonOut(['success' => false, 'message' => 'Name, email, and password are required.']);

    $stmt = $db->prepare("SELECT patient_id FROM patient WHERE email=?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) jsonOut(['success' => false, 'message' => 'Email already registered.']);

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO patient (name,email,phone,password,gender,age,address) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssis', $name, $email, $phone, $hash, $gender, $age, $address);
    if ($stmt->execute()) {
        jsonOut(['success' => true, 'message' => 'Registration successful. Please login.']);
    } else {
        jsonOut(['success' => false, 'message' => 'Registration failed: ' . $db->error]);
    }
}

/** POST /backend.php?action=login */
function login(): void
{
    startSession();

    $db    = getDB();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $role  = $_POST['role'] ?? 'patient';

    $table = match ($role) {
        'admin'   => 'admin',
        'doctor'  => 'doctor',
        default   => 'patient'
    };

    $idCol = match ($role) {
        'admin'  => 'admin_id',
        'doctor' => 'doctor_id',
        default  => 'patient_id'
    };

    $stmt = $db->prepare("SELECT * FROM `$table` WHERE email=? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    // DIRECT PASSWORD CHECK
    if (!$user || $pass != $user['password']) {

        jsonOut([
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    $_SESSION['user'] = [
        'id'    => $user[$idCol],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $role
    ];

    unset($user['password']);

    jsonOut([
        'success' => true,
        'user'    => $_SESSION['user'],
        'message' => 'Login successful.'
    ]);
}

/** POST /backend.php?action=logout */
function logout(): void
{
    startSession();
    session_destroy();
    jsonOut(['success' => true, 'message' => 'Logged out.']);
}

// ─── DEPARTMENTS ──────────────────────────────────────────────────────────────

/** GET /backend.php?action=get_departments */
function getDepartments(): void
{
    $db  = getDB();
    $res = $db->query("SELECT * FROM department ORDER BY department_name");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

/** POST /backend.php?action=add_department (admin only) */
function addDepartment(): void
{
    requireRole('admin');
    $db   = getDB();
    $name = trim($_POST['department_name'] ?? '');
    if (!$name) jsonOut(['success' => false, 'message' => 'Department name required.']);
    $stmt = $db->prepare("INSERT INTO department (department_name) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Department added.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

// ─── DOCTORS ─────────────────────────────────────────────────────────────────

/** GET /backend.php?action=get_doctors[&department_id=X][&search=Y] */
function getDoctors(): void
{
    $db   = getDB();
    $dept = (int)($_GET['department_id'] ?? 0);
    $srch = trim($_GET['search'] ?? '');
    $sql  = "SELECT d.*, dep.department_name FROM doctor d JOIN department dep ON d.department_id=dep.department_id WHERE d.status='active'";
    if ($dept) $sql .= " AND d.department_id=$dept";
    if ($srch) {
        $s = $db->real_escape_string($srch);
        $sql .= " AND (d.name LIKE '%$s%' OR d.specialization LIKE '%$s%')";
    }
    $sql .= " ORDER BY d.name";
    $res  = $db->query($sql);
    $out  = [];
    while ($row = $res->fetch_assoc()) {
        unset($row['password']);
        $out[] = $row;
    }
    jsonOut(['success' => true, 'data' => $out]);
}

/** GET /backend.php?action=get_doctor&doctor_id=X */
function getDoctor(): void
{
    $db  = getDB();
    $id  = (int)($_GET['doctor_id'] ?? 0);
    $res = $db->query("SELECT d.*, dep.department_name FROM doctor d JOIN department dep ON d.department_id=dep.department_id WHERE d.doctor_id=$id LIMIT 1");
    $row = $res->fetch_assoc();
    if (!$row) jsonOut(['success' => false, 'message' => 'Doctor not found.']);
    unset($row['password']);
    jsonOut(['success' => true, 'data' => $row]);
}

/** POST /backend.php?action=add_doctor (admin only) */
function addDoctor(): void
{
    requireRole('admin');
    $db    = getDB();
    $deptId = (int)($_POST['department_id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $spec   = trim($_POST['specialization'] ?? '');
    $fee    = (float)($_POST['fee'] ?? 500);
    $pass   = password_hash($_POST['password'] ?? 'doctor123', PASSWORD_BCRYPT);
    if (!$name || !$email || !$deptId) jsonOut(['success' => false, 'message' => 'Name, email, department required.']);
    $stmt = $db->prepare("INSERT INTO doctor (department_id,name,email,phone,password,specialization,fee) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('isssssd', $deptId, $name, $email, $phone, $pass, $spec, $fee);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Doctor added.', 'doctor_id' => $db->insert_id]) : jsonOut(['success' => false, 'message' => $db->error]);
}

/** POST /backend.php?action=update_doctor (admin only) */
function updateDoctor(): void
{
    requireRole('admin');
    $db   = getDB();
    $id   = (int)($_POST['doctor_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $spec = trim($_POST['specialization'] ?? '');
    $fee  = (float)($_POST['fee'] ?? 500);
    $status = $_POST['status'] ?? 'active';
    $stmt = $db->prepare("UPDATE doctor SET name=?,specialization=?,fee=?,status=? WHERE doctor_id=?");
    $stmt->bind_param('ssdsi', $name, $spec, $fee, $status, $id);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Doctor updated.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

/** POST /backend.php?action=delete_doctor (admin only) */
function deleteDoctor(): void
{
    requireRole('admin');
    $db = getDB();
    $id = (int)($_POST['doctor_id'] ?? 0);
    $db->query("DELETE FROM doctor WHERE doctor_id=$id");
    jsonOut(['success' => true, 'message' => 'Doctor removed.']);
}

// ─── DOCTOR AVAILABILITY ──────────────────────────────────────────────────────

/** GET /backend.php?action=get_doctor_availability&doctor_id=X */
function getDoctorAvailability(): void
{
    $db  = getDB();
    $id  = (int)($_GET['doctor_id'] ?? 0);
    $res = $db->query("SELECT * FROM doctor_availability WHERE doctor_id=$id AND is_available=1 ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

/** POST /backend.php?action=set_availability (doctor only) */
function setAvailability(): void
{
    requireRole('doctor');
    $db  = getDB();
    $uid = currentUser()['id'];
    $day   = $_POST['day_of_week'] ?? '';
    $start = $_POST['start_time']  ?? '';
    $end   = $_POST['end_time']    ?? '';
    $avail = (int)($_POST['is_available'] ?? 1);
    // Upsert
    $stmt  = $db->prepare("INSERT INTO doctor_availability (doctor_id,day_of_week,start_time,end_time,is_available) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time),end_time=VALUES(end_time),is_available=VALUES(is_available)");
    $stmt->bind_param('isssi', $uid, $day, $start, $end, $avail);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Availability updated.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

// ─── APPOINTMENTS ─────────────────────────────────────────────────────────────

/** POST /backend.php?action=book_appointment (patient only) */
function bookAppointment(): void
{
    requireRole('patient');
    $db    = getDB();
    $pid   = currentUser()['id'];
    $did   = (int)($_POST['doctor_id'] ?? 0);
    $date  = $_POST['date'] ?? '';
    $time  = $_POST['time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    if (!$did || !$date || !$time) jsonOut(['success' => false, 'message' => 'Doctor, date, time required.']);

    // Check for clash
    $stmt = $db->prepare("SELECT appointment_id FROM appointment WHERE doctor_id=? AND date=? AND time=? AND status NOT IN ('cancelled')");
    $stmt->bind_param('iss', $did, $date, $time);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) jsonOut(['success' => false, 'message' => 'This slot is already booked.']);

    $stmt = $db->prepare("INSERT INTO appointment (patient_id,doctor_id,date,time,notes) VALUES (?,?,?,?,?)");
    $stmt->bind_param('iisss', $pid, $did, $date, $time, $notes);
    if (!$stmt->execute()) jsonOut(['success' => false, 'message' => $db->error]);
    $apptId = $db->insert_id;

    // Auto-generate bill
    $res = $db->query("SELECT fee FROM doctor WHERE doctor_id=$did");
    $fee = $res->fetch_assoc()['fee'] ?? 500;
    $stmt2 = $db->prepare("INSERT INTO billing (appointment_id,patient_id,amount,date) VALUES (?,?,?,?)");
    $stmt2->bind_param('iids', $apptId, $pid, $fee, $date);
    $stmt2->execute();

    jsonOut(['success' => true, 'message' => 'Appointment booked successfully.', 'appointment_id' => $apptId]);
}

/** GET /backend.php?action=get_appointments[&role=patient|doctor|admin] */
function getAppointments(): void
{
    $db   = getDB();
    $user = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);

    $sql = "SELECT a.*, p.name AS patient_name, d.name AS doctor_name, d.specialization, dep.department_name
            FROM appointment a
            JOIN patient p    ON a.patient_id=p.patient_id
            JOIN doctor d     ON a.doctor_id=d.doctor_id
            JOIN department dep ON d.department_id=dep.department_id";

    if ($user['role'] === 'patient') {
        $sql .= " WHERE a.patient_id={$user['id']}";
    } elseif ($user['role'] === 'doctor') {
        $sql .= " WHERE a.doctor_id={$user['id']}";
    }
    $sql .= " ORDER BY a.date DESC, a.time DESC";

    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

/** POST /backend.php?action=update_appointment_status */
function updateAppointmentStatus(): void
{
    $db     = getDB();
    $user   = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);
    $id     = (int)($_POST['appointment_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['pending', 'confirmed', 'cancelled', 'completed'];
    if (!in_array($status, $allowed)) jsonOut(['success' => false, 'message' => 'Invalid status.']);

    // Patients can only cancel their own
    if ($user['role'] === 'patient') {
        $stmt = $db->prepare("UPDATE appointment SET status=? WHERE appointment_id=? AND patient_id=?");
        $stmt->bind_param('sii', $status, $id, $user['id']);
    } else {
        $stmt = $db->prepare("UPDATE appointment SET status=? WHERE appointment_id=?");
        $stmt->bind_param('si', $status, $id);
    }
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Status updated.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

// ─── BILLING ──────────────────────────────────────────────────────────────────

/** GET /backend.php?action=get_billing */
function getBilling(): void
{
    $db   = getDB();
    $user = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);

    $sql = "SELECT b.*, p.name AS patient_name, d.name AS doctor_name, a.date AS appt_date, a.time AS appt_time
            FROM billing b
            JOIN appointment a ON b.appointment_id=a.appointment_id
            JOIN patient p     ON b.patient_id=p.patient_id
            JOIN doctor d      ON a.doctor_id=d.doctor_id";

    if ($user['role'] === 'patient') $sql .= " WHERE b.patient_id={$user['id']}";
    $sql .= " ORDER BY b.date DESC";

    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

/** POST /backend.php?action=pay_bill (patient) */
function payBill(): void
{
    requireRole('patient');
    $db  = getDB();
    $bid = (int)($_POST['bill_id'] ?? 0);
    $uid = currentUser()['id'];
    $stmt = $db->prepare("UPDATE billing SET status='paid' WHERE bill_id=? AND patient_id=?");
    $stmt->bind_param('ii', $bid, $uid);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Payment confirmed.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

// ─── MEDICINE ─────────────────────────────────────────────────────────────────

/** POST /backend.php?action=add_medicine (doctor only) */
function addMedicine(): void
{
    requireRole('doctor');
    $db     = getDB();
    $pid    = (int)($_POST['patient_id'] ?? 0);
    $apptId = (int)($_POST['appointment_id'] ?? 0) ?: null;
    $name   = trim($_POST['name'] ?? '');
    $qty    = trim($_POST['quality'] ?? '');
    $exp    = $_POST['expire_date'] ?? null;
    if (!$pid || !$name) jsonOut(['success' => false, 'message' => 'Patient ID and medicine name required.']);
    $stmt = $db->prepare("INSERT INTO medicine (appointment_id,patient_id,name,quality,expire_date) VALUES (?,?,?,?,?)");
    $stmt->bind_param('iisss', $apptId, $pid, $name, $qty, $exp);
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Medicine added.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

/** GET /backend.php?action=get_medicine&patient_id=X */
function getMedicine(): void
{
    $db  = getDB();
    $pid = (int)($_GET['patient_id'] ?? 0);
    if (!$pid) jsonOut(['success' => false, 'message' => 'Patient ID required.']);
    $res = $db->query("SELECT * FROM medicine WHERE patient_id=$pid ORDER BY created_at DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

// ─── PATIENTS (admin) ─────────────────────────────────────────────────────────

/** GET /backend.php?action=get_patients (admin only) */
function getPatients(): void
{
    requireRole('admin');
    $db  = getDB();
    $res = $db->query("SELECT patient_id,name,email,phone,gender,age,address,created_at FROM patient ORDER BY created_at DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    jsonOut(['success' => true, 'data' => $out]);
}

/** GET /backend.php?action=get_profile */
function getProfile(): void
{
    $db   = getDB();
    $user = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);

    $table = match ($user['role']) {
        'admin'  => ['admin',  'admin_id'],
        'doctor' => ['doctor', 'doctor_id'],
        default  => ['patient', 'patient_id']
    };
    $res = $db->query("SELECT * FROM {$table[0]} WHERE {$table[1]}={$user['id']} LIMIT 1");
    $row = $res->fetch_assoc();
    unset($row['password']);
    jsonOut(['success' => true, 'data' => $row]);
}

/** POST /backend.php?action=update_profile */
function updateProfile(): void
{
    $db   = getDB();
    $user = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);

    $name  = trim($_POST['name']  ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($user['role'] === 'patient') {
        $age     = (int)($_POST['age'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        $stmt    = $db->prepare("UPDATE patient SET name=?,phone=?,age=?,address=? WHERE patient_id=?");
        $stmt->bind_param('ssisi', $name, $phone, $age, $address, $user['id']);
    } elseif ($user['role'] === 'doctor') {
        $spec = trim($_POST['specialization'] ?? '');
        $stmt = $db->prepare("UPDATE doctor SET name=?,phone=?,specialization=? WHERE doctor_id=?");
        $stmt->bind_param('sssi', $name, $phone, $spec, $user['id']);
    } else {
        $stmt = $db->prepare("UPDATE admin SET name=? WHERE admin_id=?");
        $stmt->bind_param('si', $name, $user['id']);
    }
    $stmt->execute() ? jsonOut(['success' => true, 'message' => 'Profile updated.']) : jsonOut(['success' => false, 'message' => $db->error]);
}

/** POST /backend.php?action=change_password */
function changePassword(): void
{
    $db      = getDB();
    $user    = currentUser();
    if (!$user) jsonOut(['success' => false, 'message' => 'Not logged in.']);
    $old     = $_POST['old_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    if (!$old || !$new) jsonOut(['success' => false, 'message' => 'Both old and new password required.']);

    [$table, $idCol] = match ($user['role']) {
        'admin'  => ['admin',  'admin_id'],
        'doctor' => ['doctor', 'doctor_id'],
        default  => ['patient', 'patient_id']
    };
    $res  = $db->query("SELECT password FROM `$table` WHERE $idCol={$user['id']}");
    $row  = $res->fetch_assoc();
    if (!password_verify($old, $row['password'])) jsonOut(['success' => false, 'message' => 'Current password incorrect.']);
    $hash = password_hash($new, PASSWORD_BCRYPT);
    $db->query("UPDATE `$table` SET password='$hash' WHERE $idCol={$user['id']}");
    jsonOut(['success' => true, 'message' => 'Password changed successfully.']);
}

// ─── STATS (admin dashboard) ─────────────────────────────────────────────────

/** GET /backend.php?action=get_stats (admin only) */
function getStats(): void
{
    requireRole('admin');
    $db = getDB();
    $stats = [];
    $stats['total_patients']     = $db->query("SELECT COUNT(*) c FROM patient")->fetch_assoc()['c'];
    $stats['total_doctors']      = $db->query("SELECT COUNT(*) c FROM doctor")->fetch_assoc()['c'];
    $stats['total_appointments'] = $db->query("SELECT COUNT(*) c FROM appointment")->fetch_assoc()['c'];
    $stats['pending_appointments'] = $db->query("SELECT COUNT(*) c FROM appointment WHERE status='pending'")->fetch_assoc()['c'];
    $stats['total_revenue']      = $db->query("SELECT COALESCE(SUM(amount),0) c FROM billing WHERE status='paid'")->fetch_assoc()['c'];
    $stats['unpaid_bills']       = $db->query("SELECT COUNT(*) c FROM billing WHERE status='unpaid'")->fetch_assoc()['c'];
    jsonOut(['success' => true, 'data' => $stats]);
}

// ─── DOCTOR FREE TIME SLOTS ──────────────────────────────────────────────────

/**
 * GET /backend.php?action=get_free_times&doctor_id=X&date=YYYY-MM-DD
 *
 * How it works:
 *  1. Find the doctor's working hours for the day of week matching the given date.
 *  2. Generate all 30-minute slots within those hours.
 *  3. Remove slots that are already booked (status != 'cancelled').
 *  4. Return the remaining FREE slots.
 *
 * Example response:
 *  { "success": true, "date": "2026-05-05", "day": "Monday",
 *    "work_start": "09:00", "work_end": "17:00",
 *    "free_slots": ["09:00","09:30","10:00",...] }
 */
function getFreeTimes(): void
{
    $db        = getDB();
    $doctor_id = (int)($_GET['doctor_id'] ?? 0);
    $date      = $_GET['date'] ?? '';

    // Basic validation
    if (!$doctor_id || !$date) {
        jsonOut(['success' => false, 'message' => 'doctor_id and date are required.']);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonOut(['success' => false, 'message' => 'date must be YYYY-MM-DD format.']);
        return;
    }

    // Get the day name (e.g. "Monday") from the given date
    $dayName = date('l', strtotime($date));   // 'l' = full day name

    // Step 1: Get doctor's working hours for that day
    $stmt = $db->prepare(
        "SELECT start_time, end_time FROM doctor_availability
         WHERE doctor_id = ? AND day_of_week = ? AND is_available = 1
         LIMIT 1"
    );
    $stmt->bind_param('is', $doctor_id, $dayName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        jsonOut([
            'success'    => true,
            'date'       => $date,
            'day'        => $dayName,
            'free_slots' => [],
            'message'    => "Doctor is not available on $dayName."
        ]);
        return;
    }

    $workStart = $row['start_time'];   // e.g. "09:00:00"
    $workEnd   = $row['end_time'];     // e.g. "17:00:00"

    // Step 2: Generate all 30-minute slots between work_start and work_end
    $allSlots = [];
    $current  = strtotime("1970-01-01 $workStart");
    $end      = strtotime("1970-01-01 $workEnd");

    while ($current < $end) {
        $allSlots[] = date('H:i', $current);   // "09:00", "09:30", etc.
        $current   += 30 * 60;                 // add 30 minutes
    }

    // Step 3: Get already booked times for this doctor on this date
    $stmt2 = $db->prepare(
        "SELECT TIME_FORMAT(time, '%H:%i') AS booked_time
         FROM appointment
         WHERE doctor_id = ? AND date = ? AND status NOT IN ('cancelled')"
    );
    $stmt2->bind_param('is', $doctor_id, $date);
    $stmt2->execute();
    $result     = $stmt2->get_result();
    $bookedTimes = [];
    while ($r = $result->fetch_assoc()) {
        $bookedTimes[] = $r['booked_time'];
    }

    // Step 4: Keep only slots that are NOT booked
    $freeSlots = array_values(array_diff($allSlots, $bookedTimes));

    jsonOut([
        'success'     => true,
        'date'        => $date,
        'day'         => $dayName,
        'work_start'  => substr($workStart, 0, 5),   // trim seconds "09:00"
        'work_end'    => substr($workEnd,   0, 5),
        'total_slots' => count($allSlots),
        'booked'      => count($bookedTimes),
        'free_slots'  => $freeSlots
    ]);
}

// ─── ROUTER ───────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    match ($action) {
        'setup'                    => setupDatabase(),
        'register_patient'         => registerPatient(),
        'login'                    => login(),
        'logout'                   => logout(),
        'get_departments'          => getDepartments(),
        'add_department'           => addDepartment(),
        'get_doctors'              => getDoctors(),
        'get_doctor'               => getDoctor(),
        'add_doctor'               => addDoctor(),
        'update_doctor'            => updateDoctor(),
        'delete_doctor'            => deleteDoctor(),
        'get_doctor_availability'  => getDoctorAvailability(),
        'set_availability'         => setAvailability(),
        'get_free_times'           => getFreeTimes(),
        'book_appointment'         => bookAppointment(),
        'get_appointments'         => getAppointments(),
        'update_appointment_status' => updateAppointmentStatus(),
        'get_billing'              => getBilling(),
        'pay_bill'                 => payBill(),
        'add_medicine'             => addMedicine(),
        'get_medicine'             => getMedicine(),
        'get_patients'             => getPatients(),
        'get_profile'              => getProfile(),
        'update_profile'           => updateProfile(),
        'change_password'          => changePassword(),
        'get_stats'                => getStats(),
        default                    => jsonOut(['success' => false, 'message' => "Unknown action: '$action'. Visit ?action=setup to initialize the database."])
    };
}
