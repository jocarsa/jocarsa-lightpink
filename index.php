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
    CREATE TABLE IF NOT EXISTS day_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        time_slot TEXT NOT NULL,
        description TEXT,
        color_tag TEXT,
        group_flag INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id)
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
// 7. Manejo de AJAX (guardar datos sin recargar)
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
        $date       = $_POST['date']       ?? date('Y-m-d');
        $time_slot  = $_POST['time_slot']  ?? '';
        $desc       = trim($_POST['description'] ?? '');
        $color_tag  = $_POST['color_tag']  ?? '';
        $group_flag = isset($_POST['group_flag']) ? (int)$_POST['group_flag'] : 0;

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
            // Update
            $upd = $db->prepare("
                UPDATE day_slots
                   SET description = :desc,
                       color_tag   = :color_tag,
                       group_flag  = :group_flag
                 WHERE id = :id
            ");
            $upd->bindValue(':desc',       $desc);
            $upd->bindValue(':color_tag',  $color_tag);
            $upd->bindValue(':group_flag', $group_flag);
            $upd->bindValue(':id',         $exists['id']);
            $upd->execute();
        } else {
            // Insert
            $ins = $db->prepare("
                INSERT INTO day_slots (user_id, date, time_slot, description, color_tag, group_flag)
                VALUES (:u, :d, :ts, :desc, :color_tag, :group_flag)
            ");
            $ins->bindValue(':u',          $user_id);
            $ins->bindValue(':d',          $date);
            $ins->bindValue(':ts',         $time_slot);
            $ins->bindValue(':desc',       $desc);
            $ins->bindValue(':color_tag',  $color_tag);
            $ins->bindValue(':group_flag', $group_flag);
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
            // Update
            $upd = $db->prepare("
                UPDATE daily_data
                   SET $field = :val
                 WHERE id = :id
            ");
            $upd->bindValue(':val', $value);
            $upd->bindValue(':id', $row['id']);
            $upd->execute();
        } else {
            // Insert
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

    // Acción no válida
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}

// ----------------------------------------------------------------------
// 8. Determinar vista: 'day' (diaria) o 'month' (mensual)
// ----------------------------------------------------------------------
$view = isset($_GET['view']) ? $_GET['view'] : 'day';

// ----------------------------------------------------------------------
// 9. Lógica para la vista diaria
// ----------------------------------------------------------------------
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($view === 'day') {
    // Navegación anterior
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        $time = strtotime($currentDate) - 86400;
        $currentDate = date('Y-m-d', $time);
    }
    // Navegación siguiente
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        $time = strtotime($currentDate) + 86400;
        $currentDate = date('Y-m-d', $time);
    }

    // Recuperar data
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

        // b) Generar slots desde 06:00 a 24:00 cada 30'
        $start = strtotime("06:00");
        $end   = strtotime("24:00");
        for ($t = $start; $t < $end; $t += 1800) {
            $timeSlots[] = date('H:i', $t);
        }

        // c) day_slots (ya existentes)
        $slotStmt = $db->prepare("
            SELECT time_slot, description, color_tag, group_flag
            FROM day_slots
            WHERE user_id = :u 
              AND date = :d
        ");
        $slotStmt->bindValue(':u', $user_id);
        $slotStmt->bindValue(':d', $currentDate);
        $slotRes = $slotStmt->execute();
        while ($row = $slotRes->fetchArray(SQLITE3_ASSOC)) {
            $ts = $row['time_slot'];
            $daySlotData[$ts] = [
                'description' => $row['description'],
                'color_tag'   => $row['color_tag'],
                'group_flag'  => $row['group_flag']
            ];
        }
    }
}

// ----------------------------------------------------------------------
// 10. Lógica para la vista mensual
// ----------------------------------------------------------------------
if ($view === 'month') {
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

    // Mes anterior
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        $prevMonth = strtotime("-1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $prevMonth);
        $month = date('n', $prevMonth);
    }
    // Mes siguiente
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        $nextMonth = strtotime("+1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $nextMonth);
        $month = date('n', $nextMonth);
    }

    $firstDayOfMonth = strtotime("$year-$month-01");
    $daysInMonth     = date('t', $firstDayOfMonth);
    $monthStart      = date('Y-m-d', $firstDayOfMonth);
    $monthEnd        = date('Y-m-d', strtotime("$year-$month-$daysInMonth"));
    $monthEvents     = [];

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
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
				 // skip empty descriptions
				 if (!empty(trim($row['description']))) {
					  $day = $row['date'];
					  if (!isset($monthEvents[$day])) {
						   $monthEvents[$day] = [];
					  }
					  $monthEvents[$day][] = [
						   'time_slot'   => $row['time_slot'],
						   'description' => $row['description']
					  ];
				 }
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
    <!-- IMPORTANT for mobile responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>jocarsa | lightpink</title>
    <link rel="stylesheet" href="style.css">
    <!-- Standard Favicon -->
    <link rel="icon" href="lightpink.png" type="image/x-icon">

    <!-- PNG Favicon for Browsers -->
    <link rel="icon" type="image/png" sizes="32x32" href="lightpink.png">
    <link rel="icon" type="image/png" sizes="16x16" href="lightpink.png">

    <!-- Apple Touch Icon (iOS) -->
    <link rel="apple-touch-icon" sizes="180x180" href="/lightpink.png">

   

    <!-- Theme Color for Chrome on Android -->
    <meta name="theme-color" content="#ffffff">
</head>
<body>

<header class="top-header">
    <!-- 1. Corporate Identity -->
    <div class="header-title">
        <h1 title="Tu agenda diaria en línea">
            <img src="lightpink.png" alt="logo">jocarsa | lightpink
        </h1>
    </div>

    <!-- 2. If logged in, show user info, day nav, links -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="header-userinfo">

            <!-- User Name -->
            <div class="welcome" title="Nombre del usuario conectado">
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </div>

            <!-- Day Navigation (only if day view) -->
            <?php if ($view === 'day'): ?>
                <nav class="date-nav">
                    <a href="?view=day&prev=1&date=<?php echo $currentDate; ?>" 
                       title="Día anterior">&lt;</a>
                    <span><?php echo $currentDate; ?></span>
                    <a href="?view=day&next=1&date=<?php echo $currentDate; ?>" 
                       title="Día siguiente">&gt;</a>
                </nav>
            <?php endif; ?>

            <!-- Additional Links (month/day toggle, logout) -->
            <div class="header-links">
                <?php if ($view === 'day'): ?>
                    <a href="?view=month" title="Ver vista mensual">&#128197; Mes</a>
                <?php else: ?>
                    <a href="?view=day" title="Ver vista diaria">&#128337; Día</a>
                <?php endif; ?>

                <a class="logout-link" href="index.php?action=logout"
                   title="Cerrar Sesión">Salir</a>
            </div>
        </div>
    <?php endif; ?>
</header>

<?php if (isset($message)): ?>
    <div class="message">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!isset($_SESSION['user_id'])): ?>
    <!-- Login & Registro -->
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
    <!-- ****************************** -->
    <!-- VISTA MENSUAL (CALENDARIO) -->
    <!-- ****************************** -->
    <?php if ($view === 'month'): ?>
        <?php
        $firstDayOfMonth  = isset($firstDayOfMonth) ? $firstDayOfMonth : strtotime(date('Y-m-01'));
        $daysInMonth      = isset($daysInMonth)     ? $daysInMonth     : date('t');
        $startWeekDay     = date('w', $firstDayOfMonth); // 0=Domingo
        setlocale(LC_TIME, 'es_ES.UTF-8'); // para nombre de mes en español (si el server lo soporta)
        $monthName        = strftime('%B', $firstDayOfMonth);
        ?>

        <div class="month-container" style="padding:20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <a style="text-decoration:none; font-size:1.2rem;" 
                   href="?view=month&prev=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&lt;&lt;</a>
                
                <div style="font-weight:bold; font-size:1.1rem;">
                    <?php echo ucfirst($monthName) . " " . $year; ?>
                </div>

                <a style="text-decoration:none; font-size:1.2rem;" 
                   href="?view=month&next=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&gt;&gt;</a>
            </div>

            <table class="month-table">
                <thead>
                    <tr>
                        <th>Dom</th>
                        <th>Lun</th>
                        <th>Mar</th>
                        <th>Mié</th>
                        <th>Jue</th>
                        <th>Vie</th>
                        <th>Sáb</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $dayCounter = 1; 
                $cellCount  = 0;

                echo "<tr>";
                for ($blank = 0; $blank < $startWeekDay; $blank++) {
                    echo "<td></td>";
                    $cellCount++;
                }

                while ($dayCounter <= $daysInMonth) {
                    if ($cellCount % 7 == 0 && $cellCount != 0) {
                        echo "</tr><tr>";
                    }

                    $currentCellDate = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);
                    $events = isset($monthEvents[$currentCellDate]) ? $monthEvents[$currentCellDate] : [];

                    echo "<td>";
                    // Día con link
                    echo "<div style='font-weight:bold; margin-bottom:4px;'>
                            <a href='?view=day&date=$currentCellDate' 
                               style='text-decoration:none; color:#862D42;'>
                                $dayCounter
                            </a>
                          </div>";

                    // Eventos
                    foreach ($events as $ev) {
                        $t = htmlspecialchars($ev['time_slot']);
                        $d = htmlspecialchars($ev['description']);
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

                // Celdas vacías para completar la última fila
                while ($cellCount % 7 != 0) {
                    echo "<td></td>";
                    $cellCount++;
                }
                echo "</tr>";
                ?>
                </tbody>
            </table>
        </div>

    <!-- ****************************** -->
    <!-- VISTA DIARIA (SLOTS + DATOS) -->
    <!-- ****************************** -->
    <?php else: ?>
        <div class="agenda-container">
            <!-- Panel Izquierdo: Time Slots -->
            <div class="left-panel">
                <table class="time-table" title="Tabla de horarios (cada 30 minutos)">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Color</th>
                            <th>Group</th>
                            <th>Contenido</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($timeSlots as $slot):
                        $desc      = $daySlotData[$slot]['description'] ?? '';
                        $colorTag  = $daySlotData[$slot]['color_tag']   ?? '#ffffff';
                        $groupFlag = $daySlotData[$slot]['group_flag']  ?? 0;
                        $checked   = $groupFlag ? 'checked' : '';
                    ?>
                        <tr class="time-slot-row" data-timeslot="<?php echo $slot; ?>">
                            <!-- Hora -->
                            <td class="slot-label"><?php echo $slot; ?></td>
                            <!-- Color -->
                            <td class="slot-color">
                                <input type="color"
                                       class="slot-color-input"
                                       data-timeslot="<?php echo $slot; ?>"
                                       value="<?php echo htmlspecialchars($colorTag); ?>">
                            </td>
                            <!-- Group -->
                            <td class="slot-group">
                                <input type="checkbox"
                                       class="slot-group-input"
                                       data-timeslot="<?php echo $slot; ?>"
                                       <?php echo $checked; ?>>
                            </td>
                            <!-- Contenido -->
                            <td>
                                <textarea class="slot-input"
                                          data-timeslot="<?php echo $slot; ?>"
                                          title="Notas para la hora <?php echo $slot; ?>"
                                ><?php echo htmlspecialchars($desc); ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Panel Derecho: Datos Diarios -->
            <div class="right-panel">
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

                <div class="exercise-row">
                    <div>
                        <label>Tiempo de Ejercicio (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="exercise_time"
                               value="<?php echo $exercise_time; ?>">
                    </div>
                    <div>
                        <label>Descripción de Ejercicio</label>
                        <input type="text" class="daily-data" data-field="exercise_desc"
                               value="<?php echo htmlspecialchars($exercise_desc); ?>"
                               placeholder="Ej: Correr, Yoga, Pesas...">
                    </div>
                </div>
            </div>
        </div> <!-- End agenda-container -->

        <!-- Script para guardado AJAX (slots y daily_data) -->
        <script>
        (function(){
            const currentDate = "<?php echo $currentDate; ?>";

            // Toggle group checkbox by clicking entire <td>
            document.querySelectorAll('.slot-group').forEach(td => {
                td.addEventListener('click', function(e){
                    if (e.target.classList.contains('slot-group-input')) {
                        return; 
                    }
                    const checkbox = td.querySelector('.slot-group-input');
                    checkbox.checked = !checkbox.checked;
                    saveSlotFromRow(this.closest('tr.time-slot-row'));
                    refreshGroupingVisual();
                    e.stopPropagation();
                });
            });

            // Check/uncheck directly
            document.querySelectorAll('.slot-group-input').forEach(cb => {
                cb.addEventListener('change', function(){
                    saveSlotFromRow(cb.closest('tr.time-slot-row'));
                    refreshGroupingVisual();
                });
            });

            // Color changes
            document.querySelectorAll('.slot-color-input').forEach(elem => {
                elem.addEventListener('input', function(){
                    const row = this.closest('tr.time-slot-row');
                    row.style.backgroundColor = this.value;
                    saveSlotFromRow(row);
                });
            });

            // Description changes
            document.querySelectorAll('.slot-input').forEach(elem => {
                elem.addEventListener('input', function(){
                    const row = this.closest('tr.time-slot-row');
                    saveSlotFromRow(row);
                });
            });

            // Save slot
            function saveSlotFromRow(row) {
                const timeSlot    = row.getAttribute('data-timeslot');
                const description = row.querySelector('.slot-input').value;
                const colorTag    = row.querySelector('.slot-color-input').value;
                const groupFlag   = row.querySelector('.slot-group-input').checked ? 1 : 0;

                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_slot',
                        date: currentDate,
                        time_slot: timeSlot,
                        description: description,
                        color_tag: colorTag,
                        group_flag: groupFlag
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

            // Daily data changes
            document.querySelectorAll('.daily-data').forEach(function(elem){
                elem.addEventListener('input', function(){
                    let field = this.getAttribute('data-field');
                    let value = this.value;
                    saveDailyData(field, value);
                });
            });
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

            // Refresh grouping lines
            function refreshGroupingVisual() {
                const rows = Array.from(document.querySelectorAll('.time-slot-row'));
                rows.forEach(r => {
                    const tdGroup = r.querySelector('.slot-group');
                    tdGroup.classList.remove('grouped','group-start','group-end');
                });

                let groupActive = false;
                let startIndex  = -1;

                for (let i = 0; i < rows.length; i++) {
                    const cb  = rows[i].querySelector('.slot-group-input');
                    const tdG = rows[i].querySelector('.slot-group');
                    if (cb && cb.checked) {
                        tdG.classList.add('grouped');
                        if (!groupActive) {
                            groupActive = true;
                            startIndex  = i;
                        }
                    } else {
                        if (groupActive) {
                            rows[i-1].querySelector('.slot-group').classList.add('group-end');
                            rows[startIndex].querySelector('.slot-group').classList.add('group-start');
                        }
                        groupActive = false;
                    }
                }
                if (groupActive) {
                    rows[rows.length - 1].querySelector('.slot-group').classList.add('group-end');
                    rows[startIndex].querySelector('.slot-group').classList.add('group-start');
                }
            }

            // On page load
            window.addEventListener('load', function(){
                document.querySelectorAll('.time-slot-row').forEach(row => {
                    const colorVal = row.querySelector('.slot-color-input').value;
                    row.style.backgroundColor = colorVal;
                });
                refreshGroupingVisual();
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>

