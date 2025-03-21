<?php
session_start();

// ----------------------------------------------------------------------
// 1. Conectar / Iniciar la base de datos SQLite
// ----------------------------------------------------------------------
$db = new SQLite3('../databases/lightpink.db');

// ----------------------------------------------------------------------
// 2. Crear tablas si no existen
// ----------------------------------------------------------------------
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS daily_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        notes TEXT,
        checklist TEXT,
        daily_menu TEXT,
        thoughts TEXT,
        sleep_time REAL DEFAULT 0,
        social_time REAL DEFAULT 0,
        me_time REAL DEFAULT 0,
        water_drinked REAL DEFAULT 0,
        meditation_time REAL DEFAULT 0,
        project_time REAL DEFAULT 0,
        exercise_time REAL DEFAULT 0,
        exercise_desc TEXT, 
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS day_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        time_slot TEXT NOT NULL,
        description TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// ----------------------------------------------------------------------
// 3. Crear usuario inicial (si no existe)
// ----------------------------------------------------------------------
$initialFullName = "Jose Vicente Carratalá";
$initialEmail    = "info@josevicentecarratala.com";
$initialUsername = "jocarsa";
$initialPassword = "jocarsa";

$stmt = $db->prepare("
    INSERT OR IGNORE INTO users (full_name, email, username, password)
    VALUES (:full_name, :email, :username, :password)
");
$stmt->bindValue(':full_name', $initialFullName);
$stmt->bindValue(':email',    $initialEmail);
$stmt->bindValue(':username', $initialUsername);
$stmt->bindValue(':password', $initialPassword);
$stmt->execute();

// ----------------------------------------------------------------------
// 4. Registro
// ----------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $username  = trim($_POST['username']);
    $password  = trim($_POST['password']);

    if ($full_name && $email && $username && $password) {
        $stmt = $db->prepare("
            INSERT INTO users (full_name, email, username, password)
            VALUES (:fn, :em, :us, :pw)
        ");
        $stmt->bindValue(':fn', $full_name);
        $stmt->bindValue(':em', $email);
        $stmt->bindValue(':us', $username);
        $stmt->bindValue(':pw', $password);
        $stmt->execute();
        $message = "Registro exitoso. Por favor, inicie sesión.";
    } else {
        $message = "Por favor, complete todos los campos de registro.";
    }
}

// ----------------------------------------------------------------------
// 5. Login
// ----------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if ($username && $password) {
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE username = :u 
              AND password = :p 
            LIMIT 1
        ");
        $stmt->bindValue(':u', $username);
        $stmt->bindValue(':p', $password);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            header("Location: index.php");
            exit;
        } else {
            $message = "Usuario o contraseña incorrectos.";
        }
    } else {
        $message = "Por favor, ingrese usuario y contraseña.";
    }
}

// ----------------------------------------------------------------------
// 6. Logout
// ----------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ----------------------------------------------------------------------
// 7. Manejo de AJAX (para guardar datos sin recargar la página)
// ----------------------------------------------------------------------
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'No ha iniciado sesión.']);
        exit;
    }
    $user_id = $_SESSION['user_id'];

    // A) Actualizar ranura horaria (slots)
    if ($_POST['action'] === 'update_slot') {
        $date      = $_POST['date'] ?? date('Y-m-d');
        $time_slot = $_POST['time_slot'] ?? '';
        $desc      = trim($_POST['description'] ?? '');

        $check = $db->prepare("
            SELECT id FROM day_slots 
            WHERE user_id = :u 
              AND date = :d 
              AND time_slot = :ts
        ");
        $check->bindValue(':u', $user_id);
        $check->bindValue(':d', $date);
        $check->bindValue(':ts', $time_slot);
        $exists = $check->execute()->fetchArray(SQLITE3_ASSOC);

        if ($exists) {
            $upd = $db->prepare("
                UPDATE day_slots
                   SET description = :desc
                 WHERE id = :id
            ");
            $upd->bindValue(':desc', $desc);
            $upd->bindValue(':id', $exists['id']);
            $upd->execute();
        } else {
            $ins = $db->prepare("
                INSERT INTO day_slots (user_id, date, time_slot, description)
                VALUES (:u, :d, :ts, :desc)
            ");
            $ins->bindValue(':u', $user_id);
            $ins->bindValue(':d', $date);
            $ins->bindValue(':ts', $time_slot);
            $ins->bindValue(':desc', $desc);
            $ins->execute();
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // B) Actualizar daily_data
    if ($_POST['action'] === 'update_daily_data') {
        $date  = $_POST['date'] ?? date('Y-m-d');
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        $allowedFields = [
            'notes', 'checklist', 'daily_menu', 'thoughts',
            'sleep_time', 'social_time', 'me_time', 'water_drinked',
            'meditation_time', 'project_time', 'exercise_time',
            'exercise_desc'
        ];
        if (!in_array($field, $allowedFields)) {
            echo json_encode(['status' => 'error', 'message' => 'Campo no permitido.']);
            exit;
        }

        $check = $db->prepare("
            SELECT id FROM daily_data 
            WHERE user_id = :u 
              AND date = :d
        ");
        $check->bindValue(':u', $user_id);
        $check->bindValue(':d', $date);
        $row = $check->execute()->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Update existing row
            $upd = $db->prepare("
                UPDATE daily_data
                   SET $field = :val
                 WHERE id = :id
            ");
            $upd->bindValue(':val', $value);
            $upd->bindValue(':id', $row['id']);
            $upd->execute();
        } else {
            // Insert a new row with the single updated field
            $insFields = [
                'notes'            => '',
                'checklist'        => '',
                'daily_menu'       => '',
                'thoughts'         => '',
                'sleep_time'       => 0,
                'social_time'      => 0,
                'me_time'          => 0,
                'water_drinked'    => 0,
                'meditation_time'  => 0,
                'project_time'     => 0,
                'exercise_time'    => 0,
                'exercise_desc'    => '',
            ];
            $insFields[$field] = $value;

            $ins = $db->prepare("
                INSERT INTO daily_data
                (user_id, date, notes, checklist, daily_menu, thoughts,
                 sleep_time, social_time, me_time, water_drinked, meditation_time,
                 project_time, exercise_time, exercise_desc)
                VALUES 
                (:u, :d, :notes, :checklist, :daily_menu, :thoughts,
                 :sleep_time, :social_time, :me_time, :water_drinked,
                 :meditation_time, :project_time, :exercise_time, :exercise_desc)
            ");
            $ins->bindValue(':u', $user_id);
            $ins->bindValue(':d', $date);
            $ins->bindValue(':notes',           $insFields['notes']);
            $ins->bindValue(':checklist',       $insFields['checklist']);
            $ins->bindValue(':daily_menu',      $insFields['daily_menu']);
            $ins->bindValue(':thoughts',        $insFields['thoughts']);
            $ins->bindValue(':sleep_time',      $insFields['sleep_time']);
            $ins->bindValue(':social_time',     $insFields['social_time']);
            $ins->bindValue(':me_time',         $insFields['me_time']);
            $ins->bindValue(':water_drinked',   $insFields['water_drinked']);
            $ins->bindValue(':meditation_time', $insFields['meditation_time']);
            $ins->bindValue(':project_time',    $insFields['project_time']);
            $ins->bindValue(':exercise_time',   $insFields['exercise_time']);
            $ins->bindValue(':exercise_desc',   $insFields['exercise_desc']);
            $ins->execute();
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // If we get here, no valid action
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}

// ----------------------------------------------------------------------
// 8. Saber si mostramos la vista "mes" (monthly) o la diaria
// ----------------------------------------------------------------------
$view = isset($_GET['view']) ? $_GET['view'] : 'day';  // 'month' or 'day'

// ----------------------------------------------------------------------
// 9. Lógica para la vista diaria
//    (solo se ejecuta si $view === 'day')
// ----------------------------------------------------------------------
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($view === 'day') {
    // Navegar anterior
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        $time = strtotime($currentDate) - 86400;
        $currentDate = date('Y-m-d', $time);
    }
    // Navegar siguiente
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        $time = strtotime($currentDate) + 86400;
        $currentDate = date('Y-m-d', $time);
    }

    // Recuperar datos para la vista diaria
    $dailyData   = [];
    $timeSlots   = [];
    $daySlotData = [];

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];

        // a) daily_data
        $stmt = $db->prepare("
            SELECT * FROM daily_data 
            WHERE user_id = :u 
              AND date = :d
        ");
        $stmt->bindValue(':u', $user_id);
        $stmt->bindValue(':d', $currentDate);
        $res = $stmt->execute();
        $dailyData = $res->fetchArray(SQLITE3_ASSOC);

        // b) slots (6:00 a 24:00 en intervalos de 30 minutos)
        $start = strtotime("06:00");
        $end   = strtotime("24:00");
        for ($t = $start; $t < $end; $t += 1800) {
            $slot = date('H:i', $t);
            $timeSlots[] = $slot;
        }

        // c) day_slots (existing data)
        $slotStmt = $db->prepare("
            SELECT time_slot, description
            FROM day_slots
            WHERE user_id = :u 
              AND date = :d
        ");
        $slotStmt->bindValue(':u', $user_id);
        $slotStmt->bindValue(':d', $currentDate);
        $slotRes = $slotStmt->execute();
        while ($row = $slotRes->fetchArray(SQLITE3_ASSOC)) {
            $daySlotData[$row['time_slot']] = $row['description'];
        }
    }
}

// ----------------------------------------------------------------------
// 10. Lógica para la vista mensual
//     (solo se ejecuta si $view === 'month')
// ----------------------------------------------------------------------
if ($view === 'month') {
    // 1) Determinar año y mes actual
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n'); // 1-12

    // 2) Cambiar mes (prev/next)
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        // Move one month back
        $prevMonth = strtotime("-1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $prevMonth);
        $month = date('n', $prevMonth);
    }
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        // Move one month forward
        $nextMonth = strtotime("+1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $nextMonth);
        $month = date('n', $nextMonth);
    }

    // 3) Construir días del mes
    $firstDayOfMonth = strtotime("$year-$month-01");
    $daysInMonth     = date('t', $firstDayOfMonth); // total days in month
    // We’ll display from Sunday of the first week to Saturday of the last
    $startWeekDay    = date('w', $firstDayOfMonth); // 0=Sunday, 1=Monday, ...
    
    // 4) Obtener los day_slots del rango [1..daysInMonth]
    $monthStart = date('Y-m-d', $firstDayOfMonth);
    $monthEnd   = date('Y-m-d', strtotime("$year-$month-$daysInMonth"));

    $monthEvents = []; // array: 'YYYY-MM-DD' => array of [time_slot, description]

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        // Query all day_slots in the month
        $slotStmt = $db->prepare("
            SELECT date, time_slot, description
            FROM day_slots
            WHERE user_id = :u
              AND date >= :start
              AND date <= :end
            ORDER BY date, time_slot
        ");
        $slotStmt->bindValue(':u', $user_id);
        $slotStmt->bindValue(':start', $monthStart);
        $slotStmt->bindValue(':end',   $monthEnd);
        $slotRes = $slotStmt->execute();
        while ($row = $slotRes->fetchArray(SQLITE3_ASSOC)) {
            $day  = $row['date'];
            $slot = [
                'time_slot'   => $row['time_slot'],
                'description' => $row['description']
            ];
            if (!isset($monthEvents[$day])) {
                $monthEvents[$day] = [];
            }
            $monthEvents[$day][] = $slot;
        }
    }
}

// ----------------------------------------------------------------------
// 11. Renderizar HTML
// ----------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <title>jocarsa | lightpink</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="top-header">
    <div class="header-title">
        <h1 title="Tu agenda diaria en línea">jocarsa | lightpink</h1>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="header-userinfo">
            <div class="welcome" title="Nombre del usuario conectado">
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </div>

            <!-- If we are on daily view, show daily nav. If in monthly, skip. -->
            <?php if ($view === 'day'): ?>
            <nav class="date-nav">
                <a href="?view=day&prev=1&date=<?php echo $currentDate; ?>" 
                   title="Ir al día anterior">&lt;</a>
                <span title="Día seleccionado"><?php echo $currentDate; ?></span>
                <a href="?view=day&next=1&date=<?php echo $currentDate; ?>" 
                   title="Ir al día siguiente">&gt;</a>
            </nav>
            <?php endif; ?>

            <!-- Link to monthly or daily view -->
            <?php if ($view === 'day'): ?>
                <!-- If currently in daily mode, link to month mode. -->
                <a style="text-decoration:none; margin-right: 15px;"
                   href="?view=month"
                   title="Ver vista mensual">&#128197; Mes</a>
            <?php else: ?>
                <!-- If currently in monthly mode, link back to daily. -->
                <a style="text-decoration:none; margin-right: 15px;"
                   href="?view=day"
                   title="Ver vista diaria">&#128337; Día</a>
            <?php endif; ?>

            <a class="logout-link" href="index.php?action=logout" 
               title="Cerrar Sesión">Salir</a>
        </div>
    <?php endif; ?>
</header>

<?php if (isset($message)): ?>
    <div class="message" title="Mensaje de estado">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!isset($_SESSION['user_id'])): ?>
    <!-- Form de Login & Registro -->
    <div class="auth-container">
        <div class="login-form">
            <h2>Iniciar Sesión</h2>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <label>Usuario</label>
                <input type="text" name="username" required>
                <label>Contraseña</label>
                <input type="password" name="password" required>
                <button type="submit">Entrar</button>
            </form>
        </div>
        <div class="register-form">
            <h2>Registrarse</h2>
            <form method="POST">
                <input type="hidden" name="action" value="register">
                <label>Nombre Completo</label>
                <input type="text" name="full_name" required>
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Usuario</label>
                <input type="text" name="username" required>
                <label>Contraseña</label>
                <input type="password" name="password" required>
                <button type="submit">Crear Cuenta</button>
            </form>
        </div>
    </div>
<?php else: ?>

    <!-- *************************************************************** -->
    <!-- NEW CODE FOR MONTHLY VIEW -->
    <!-- *************************************************************** -->
    <?php if ($view === 'month'): ?>
        <?php
        // Pre-calculate some values
        $firstDayOfMonth  = strtotime("$year-$month-01");
        $daysInMonth      = date('t', $firstDayOfMonth);
        // Sunday-based
        $startWeekDay     = date('w', $firstDayOfMonth); // 0=Sunday
        $monthName        = date('F', $firstDayOfMonth); // English month name
        // You could map $monthName to Spanish if you wish, or do strftime() with locale
        ?>

        <div class="month-container" style="padding:20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <a style="text-decoration:none; font-size:1.2rem;" 
                   href="?view=month&prev=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&lt;&lt;</a>
                
                <div style="font-weight:bold; font-size:1.1rem;">
                    <?php echo "$monthName $year"; ?>
                </div>

                <a style="text-decoration:none; font-size:1.2rem;" 
                   href="?view=month&next=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&gt;&gt;</a>
            </div>

            <table class="month-table" style="width:100%; background-color:#FFF; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#FFC8D1;">
                        <th style="padding:8px; text-align:center;">Dom</th>
                        <th style="padding:8px; text-align:center;">Lun</th>
                        <th style="padding:8px; text-align:center;">Mar</th>
                        <th style="padding:8px; text-align:center;">Mié</th>
                        <th style="padding:8px; text-align:center;">Jue</th>
                        <th style="padding:8px; text-align:center;">Vie</th>
                        <th style="padding:8px; text-align:center;">Sáb</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // We'll print rows <tr> for each week
                // Because Sunday is 0, let's line up the days accordingly
                $dayCounter = 1; 
                $cellCount  = 0;

                // The first row might have empty cells if the month doesn't start on Sunday
                echo "<tr>";
                for ($blank = 0; $blank < $startWeekDay; $blank++) {
                    echo "<td style='border:1px solid #FFD9E1; height:100px; vertical-align:top; background-color:#FFF2F5;'></td>";
                    $cellCount++;
                }

                // Now print actual days
                while ($dayCounter <= $daysInMonth) {
                    // If we've reached 7 cells in a row, start a new <tr>
                    if ($cellCount % 7 == 0 && $cellCount != 0) {
                        echo "</tr><tr>";
                    }

                    // The actual date string
                    $currentCellDate = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);

                    // Show events if any
                    $events = isset($monthEvents[$currentCellDate]) ? $monthEvents[$currentCellDate] : [];

                    echo "<td style='border:1px solid #FFD9E1; height:100px; vertical-align:top; padding:4px;'>";
                    // Day number (clickable link to daily view)
                    echo "<div style='font-weight:bold; margin-bottom:4px;'>
                            <a href='?view=day&date=$currentCellDate' style='text-decoration:none; color:#862D42;'>
                                $dayCounter
                            </a>
                          </div>";

                    // List each event as a small tag
                    foreach ($events as $ev) {
                        $t = htmlspecialchars($ev['time_slot']);
                        $d = htmlspecialchars($ev['description']);
                        // Link to that day as well
                        echo "<div style='margin-bottom:4px; font-size:0.85rem;'>
                                <a href='?view=day&date=$currentCellDate'
                                   style='color:#862D42; text-decoration:none;'>
                                   $t - $d
                                </a>
                              </div>";
                    }

                    echo "</td>";

                    $dayCounter++;
                    $cellCount++;
                }

                // Fill the remaining cells of the last row (if any)
                while ($cellCount % 7 != 0) {
                    echo "<td style='border:1px solid #FFD9E1; background-color:#FFF2F5;'></td>";
                    $cellCount++;
                }
                echo "</tr>";
                ?>
                </tbody>
            </table>
        </div>

    <!-- *************************************************************** -->
    <!-- DAILY VIEW CODE (original) -->
    <!-- *************************************************************** -->
    <?php else: ?>
        <div class="agenda-container">
            <!-- Panel Izquierdo: slots -->
            <div class="left-panel">
                <table class="time-table" title="Tabla de horarios (cada 30 minutos)">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Contenido</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($timeSlots as $slot):
                        $desc = $daySlotData[$slot] ?? '';
                    ?>
                        <tr>
                            <td class="slot-label"><?php echo $slot; ?></td>
                            <td>
                                <textarea class="slot-input"
                                          data-timeslot="<?php echo $slot; ?>"
                                          title="Agregar notas para la hora <?php echo $slot; ?>"
                                ><?php echo htmlspecialchars($desc); ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Panel Derecho: datos diarios -->
            <div class="right-panel" title="Información general de tu día">
                <?php
                $notes           = $dailyData['notes']            ?? '';
                $checklist       = $dailyData['checklist']        ?? '';
                $daily_menu      = $dailyData['daily_menu']       ?? '';
                $thoughts        = $dailyData['thoughts']         ?? '';
                $sleep_time      = $dailyData['sleep_time']       ?? 0;
                $social_time     = $dailyData['social_time']      ?? 0;
                $me_time         = $dailyData['me_time']          ?? 0;
                $water_drinked   = $dailyData['water_drinked']    ?? 0;
                $meditation_time = $dailyData['meditation_time']  ?? 0;
                $project_time    = $dailyData['project_time']     ?? 0;
                $exercise_time   = $dailyData['exercise_time']    ?? 0;
                $exercise_desc   = $dailyData['exercise_desc']    ?? '';
                ?>

                <h2>Información Diaria</h2>

                <label>Notas</label>
                <textarea class="daily-data" data-field="notes"><?php echo htmlspecialchars($notes); ?></textarea>

                <label>Lista de Tareas</label>
                <textarea class="daily-data" data-field="checklist"><?php echo htmlspecialchars($checklist); ?></textarea>

                <label>Menú Diario</label>
                <textarea class="daily-data" data-field="daily_menu"><?php echo htmlspecialchars($daily_menu); ?></textarea>

                <label>Reflexiones / Saludos</label>
                <textarea class="daily-data" data-field="thoughts"><?php echo htmlspecialchars($thoughts); ?></textarea>

                <div class="hours-grid">
                    <div>
                        <label>😴 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="sleep_time"
                               value="<?php echo $sleep_time; ?>">
                    </div>
                    <div>
                        <label>💚 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="social_time"
                               value="<?php echo $social_time; ?>">
                    </div>
                    <div>
                        <label>😊 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="me_time"
                               value="<?php echo $me_time; ?>">
                    </div>
                    <div>
                        <label>💧 (L)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="water_drinked"
                               value="<?php echo $water_drinked; ?>">
                    </div>
                    <div>
                        <label>🌞 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="meditation_time"
                               value="<?php echo $meditation_time; ?>">
                    </div>
                    <div>
                        <label>🚀 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="project_time"
                               value="<?php echo $project_time; ?>">
                    </div>
                </div>

                <div class="exercise-row" style="display: flex; gap: 15px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <label>Tiempo de Ejercicio (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="exercise_time"
                               value="<?php echo $exercise_time; ?>">
                    </div>
                    <div style="flex: 2;">
                        <label>Descripción de Ejercicio</label>
                        <input type="text" class="daily-data" data-field="exercise_desc"
                               value="<?php echo htmlspecialchars($exercise_desc); ?>"
                               placeholder="Ej: Correr, Yoga, Pesas...">
                    </div>
                </div>

            </div><!-- end right-panel -->
        </div><!-- end agenda-container -->

        <!-- Script para AJAX (mismo que antes) -->
        <script>
        (function(){
            const currentDate = "<?php echo $currentDate; ?>";

            // Escuchar cambios en cada slot
            document.querySelectorAll('.slot-input').forEach(function(elem){
                elem.addEventListener('input', function(){
                    let timeSlot = this.getAttribute('data-timeslot');
                    let description = this.value;
                    saveSlot(timeSlot, description);
                });
            });

            // Escuchar cambios en datos diarios
            document.querySelectorAll('.daily-data').forEach(function(elem){
                elem.addEventListener('input', function(){
                    let field = this.getAttribute('data-field');
                    let value = this.value;
                    saveDailyData(field, value);
                });
            });

            function saveSlot(timeSlot, description) {
                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_slot',
                        date: currentDate,
                        time_slot: timeSlot,
                        description: description
                    })
                })
                .then(r => r.json())
                .then(resp => {
                    if (resp.status !== 'ok') {
                        console.error("Error guardando slot:", resp);
                    }
                })
                .catch(err => console.error(err));
            }

            function saveDailyData(field, value) {
                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_daily_data',
                        date: currentDate,
                        field: field,
                        value: value
                    })
                })
                .then(r => r.json())
                .then(resp => {
                    if (resp.status !== 'ok') {
                        console.error("Error guardando daily data:", resp);
                    }
                })
                .catch(err => console.error(err));
            }
        })();
        </script>
    <?php endif; // end if view=day or month ?>
<?php endif; // end if logged in ?>

</body>
</html>

