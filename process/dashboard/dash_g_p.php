<?php

require '../conn.php';

$method = $_GET['method'];

if ($method == 'get_applicator_list_status_count') {
    $data = [];

    $sql = "SELECT 
                COUNT(CASE WHEN status = 'Ready To Use' THEN id END) AS total_rtu,
                COUNT(CASE WHEN status = 'Out' THEN id END) AS total_out,
                COUNT(CASE WHEN status = 'Pending' THEN id END) AS total_pending
            FROM t_applicator_list";
    $stmt = $conn -> prepare($sql);
    $stmt -> execute();

    while ($row = $stmt -> fetch(PDO::FETCH_ASSOC)) {
        $data = [
            'total_rtu' => intval($row['total_rtu']), 
            'total_out' => intval($row['total_out']), 
            'total_pending' => intval($row['total_pending'])
        ];
    }

    echo json_encode($data);
}

if ($method == 'get_total_applicator_terminal_count') {
    $data = [];

    $sql = "WITH 
                applicator_count AS (SELECT COUNT(id) AS total_applicator FROM m_applicator),
                terminal_count AS (SELECT COUNT(id) AS total_terminal FROM m_terminal),
                applicator_terminal_count AS (SELECT COUNT(id) AS total_applicator_terminal FROM m_applicator_terminal)

            SELECT 
                a.total_applicator,
                t.total_terminal,
                at.total_applicator_terminal
            FROM 
                applicator_count a,
                terminal_count t,
                applicator_terminal_count at";
    $stmt = $conn -> prepare($sql);
    $stmt -> execute();

    while ($row = $stmt -> fetch(PDO::FETCH_ASSOC)) {
        $data = [
            'total_applicator' => intval($row['total_applicator']), 
            'total_terminal' => intval($row['total_terminal']), 
            'total_applicator_terminal' => intval($row['total_applicator_terminal'])
        ];
    }

    echo json_encode($data);
}

if ($method == 'get_month_a_adj_cnt_chart_year_dropdown') {
    $sql = "SELECT DISTINCT YEAR(inspection_date_time) AS Year
            FROM t_applicator_c
            ORDER BY Year";
    $stmt = $conn -> prepare($sql);
	$stmt -> execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (count($results) > 0) {
		echo '<option selected value="">Select Year</option>';
		foreach ($results as $row) {
			echo '<option value="'.htmlspecialchars($row['Year']).'">'.htmlspecialchars($row['Year']).'</option>';
		}
	} else {
		echo '<option selected value="">Select Year</option>';
	}
}

if ($method == 'get_applicator_no_dropdown') {
	$car_maker = addslashes($_GET['car_maker']);
    $car_model = addslashes($_GET['car_model']);

	$sql = "SELECT applicator_no FROM m_applicator WHERE 1=1";
    $params = [];

	if (!empty($car_maker)) {
        $sql .= " AND car_maker = ?";
        $params[] = $car_maker;
    }

    if (!empty($car_model)) {
        $sql .= " AND car_model = ?";
        $params[] = $car_model;
    }

	$sql .= " GROUP BY applicator_no ORDER BY applicator_no ASC";

	$stmt = $conn -> prepare($sql);
	$stmt -> execute($params);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (count($results) > 0) {
		echo '<option selected value=""></option>';
		foreach ($results as $row) {
			echo '<option value="'.htmlspecialchars($row['applicator_no']).'">'.htmlspecialchars($row['applicator_no']).'</option>';
		}
	} else {
		echo '<option selected value=""></option>';
	}
}

if ($method == 'get_month_a_adj_cnt_chart') {
    $year = $_GET['year'];
    $month = $_GET['month'];
    $car_maker = $_GET['car_maker'];
    $car_model = $_GET['car_model'];
    $applicator_no = $_GET['applicator_no'];
    $adjustment_content = $_GET['adjustment_content'];

    $data = [];
    $categories = [];
    $applicatorData = [];

    $sql = "DECLARE @Year INT = ?;  -- Specify the year
            DECLARE @Month INT = ?;   -- Specify the month (November)

            WITH DateRange AS (
                SELECT 
                    DATEADD(DAY, number, DATEFROMPARTS(@Year, @Month, 1)) AS report_date
                FROM 
                    master.dbo.spt_values
                WHERE 
                    type = 'P' AND 
                    number < DAY(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)))  -- Generate dates for the month
            ),
            FilteredApplicatorHistory AS (
                SELECT 
                    a.applicator_no,
                    CAST(ac.inspection_date_time AS DATETIME2(2)) AS date_inspected
                FROM 
                    t_applicator_c ac
                LEFT JOIN 
                    m_applicator a ON 
                        ac.equipment_no = SUBSTRING(a.applicator_no, 1, CHARINDEX('/', a.applicator_no) - 1) AND 
                        ac.machine_no = SUBSTRING(a.applicator_no, CHARINDEX('/', a.applicator_no) + 1, LEN(a.applicator_no))
                WHERE 
                    ac.inspection_date_time >= DATEADD(HOUR, 6, CAST(DATEFROMPARTS(@Year, @Month, 1) AS DATETIME2)) AND 
                    ac.inspection_date_time < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)) AS DATETIME2)))  -- Adjusted to include the entire month
                    AND a.car_maker = ? AND a.car_model = ? AND ac.adjustment_content = ? AND a.applicator_no = ? 
            )

            SELECT 
                CAST(dr.report_date AS DATE) AS report_date,  -- Label the report date as DATE
                fah.applicator_no,
                COUNT(fah.applicator_no) AS total_clean
            FROM 
                DateRange dr
            LEFT JOIN 
                FilteredApplicatorHistory fah ON 
                    fah.date_inspected >= DATEADD(HOUR, 6, CAST(dr.report_date AS DATETIME2)) AND 
                    fah.date_inspected < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(dr.report_date AS DATETIME2)))  -- Adjusted to ensure the range is from 6 AM to just before 6 AM the next day
            GROUP BY 
                dr.report_date, fah.applicator_no
            ORDER BY 
                dr.report_date, fah.applicator_no";

    $stmt = $conn->prepare($sql);
    $params = array($year, $month, $car_maker, $car_model, $adjustment_content, $applicator_no);
    $stmt->execute($params);

    // Fetch results and populate the applicatorData array
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Add unique report_date to categories
        if (!in_array($row['report_date'], $categories)) {
            $categories[] = $row['report_date'];
        }

        if (!empty($row['applicator_no'])) {
            // Initialize the applicator_no in the applicatorData array if not already set
            if (!isset($applicatorData[$row['applicator_no']])) {
                $applicatorData[$row['applicator_no']] = [
                    'name' => $row['applicator_no'],
                    'data' => array_fill(0, count($categories), 0) // Initialize data array with zeros
                ];
            }

            // Find the index of the current report_date in the categories array
            $dateIndex = array_search($row['report_date'], $categories);
            
            // Update the total_clean count for the corresponding applicator_no and report_date
            if ($dateIndex !== false) {
                $applicatorData[$row['applicator_no']]['data'][$dateIndex] = intval($row['total_clean']);
            }
        }
    }

    // Initialize all applicators with zero values for all dates
    foreach ($applicatorData as $applicatorNo => $applicator) {
        // Ensure all applicators have data for all categories
        foreach ($categories as $index => $date) {
            if (!isset($applicatorData[$applicatorNo]['data'][$index])) {
                $applicatorData[$applicatorNo]['data'][$index] = 0; // Set to zero if not already set
            }
        }
    }

    // Convert the associative array to a simple indexed array
    $data = array_values($applicatorData);

    // Encode the categories and data as JSON
    echo json_encode(['categories' => $categories, 'data' => $data]);
}

if ($method == 'get_month_term_usage_chart_year_dropdown') {
    $sql = "SELECT DISTINCT YEAR(date_time_out) AS Year
            FROM t_applicator_in_out_history
            ORDER BY Year";
    $stmt = $conn -> prepare($sql);
	$stmt -> execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (count($results) > 0) {
		echo '<option selected value="">Select Year</option>';
		foreach ($results as $row) {
			echo '<option value="'.htmlspecialchars($row['Year']).'">'.htmlspecialchars($row['Year']).'</option>';
		}
	} else {
		echo '<option selected value="">Select Year</option>';
	}
}

if ($method == 'get_month_term_usage_chart') {
    $year = $_GET['year'];
    $month = $_GET['month'];
    $car_maker = $_GET['car_maker'];
    $car_model = $_GET['car_model'];
    $terminal_name = $_GET['terminal_name'];

    $data = [];
    $categories = [];
    $terminalData = [];

    $sql = "DECLARE @Year INT = ?;  -- Specify the year
            DECLARE @Month INT = ?;   -- Specify the month (November)

            WITH DateRange AS (
                SELECT 
                    DATEADD(DAY, number, DATEFROMPARTS(@Year, @Month, 1)) AS report_date
                FROM 
                    master.dbo.spt_values
                WHERE 
                    type = 'P' AND 
                    number < DAY(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)))  -- Generate dates for the month
            ),
            FilteredApplicatorHistory AS (
                SELECT 
                    aioh.applicator_no,
                    SUBSTRING(aioh.terminal_name, 1, CHARINDEX('*', aioh.terminal_name) - 1) AS terminal_name,
                    CAST(aioh.date_time_out AS DATETIME2(2)) AS date_out
                FROM 
                    t_applicator_in_out_history aioh
                LEFT JOIN 
                    m_applicator a ON aioh.applicator_no = a.applicator_no 
                WHERE 
                    aioh.date_time_out >= DATEADD(HOUR, 6, CAST(DATEFROMPARTS(@Year, @Month, 1) AS DATETIME2)) AND 
                    aioh.date_time_out < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)) AS DATETIME2)))  -- Adjusted to include the entire month
                    AND a.car_maker = ? AND a.car_model = ? AND SUBSTRING(aioh.terminal_name, 1, CHARINDEX('*', aioh.terminal_name) - 1) = ?
            )

            SELECT 
                CAST(dr.report_date AS DATE) AS report_date,  -- Label the report date as DATE
                fah.terminal_name,
                COUNT(fah.terminal_name) AS total_terminal_usage
            FROM 
                DateRange dr
            LEFT JOIN 
                FilteredApplicatorHistory fah ON 
                    fah.date_out >= DATEADD(HOUR, 6, CAST(dr.report_date AS DATETIME2)) AND 
                    fah.date_out < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(dr.report_date AS DATETIME2)))  -- Adjusted to ensure the range is from 6 AM to just before 6 AM the next day
            GROUP BY 
                dr.report_date, fah.terminal_name
            ORDER BY 
                dr.report_date, fah.terminal_name";

    $stmt = $conn->prepare($sql);
    $params = array($year, $month, $car_maker, $car_model, $terminal_name);
    $stmt->execute($params);

    // Fetch results and populate the terminalData array
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Add unique report_date to categories
        if (!in_array($row['report_date'], $categories)) {
            $categories[] = $row['report_date'];
        }

        if (!empty($row['terminal_name'])) {
            // Initialize the terminal_name in the terminalData array if not already set
            if (!isset($terminalData[$row['terminal_name']])) {
                $terminalData[$row['terminal_name']] = [
                    'name' => $row['terminal_name'],
                    'data' => array_fill(0, count($categories), 0) // Initialize data array with zeros
                ];
            }

            // Find the index of the current report_date in the categories array
            $dateIndex = array_search($row['report_date'], $categories);
            
            // Update the total_terminal_usage count for the corresponding terminal_name and report_date
            if ($dateIndex !== false) {
                $terminalData[$row['terminal_name']]['data'][$dateIndex] = intval($row['total_terminal_usage']);
            }
        }
    }

    // Initialize all terminals with zero values for all dates
    foreach ($terminalData as $terminalName => $terminal) {
        // Ensure all terminals have data for all categories
        foreach ($categories as $index => $date) {
            if (!isset($terminalData[$terminalName]['data'][$index])) {
                $terminalData[$terminalName]['data'][$index] = 0; // Set to zero if not already set
            }
        }
    }

    // Convert the associative array to a simple indexed array
    $data = array_values($terminalData);

    // Encode the categories and data as JSON
    echo json_encode(['categories' => $categories, 'data' => $data]);
}

if ($method == 'get_month_aioi_chart') {
    $year = $_GET['year'];
    $month = $_GET['month'];
    $car_maker = $_GET['car_maker'];
    $car_model = $_GET['car_model'];
    $status = $_GET['status'];

    $data = [];
    $categories = [];

    $sql = "";
    $date_time_column = "";
    $date_time_coumn2 = "";

    if ($status == 'Out') {
        $date_time_column = "date_time_out";
        $date_time_coumn2 = "date_out";
    } else if ($status == 'In') {
        $date_time_column = "date_time_in";
        $date_time_coumn2 = "date_in";
    } else if ($status == 'Inspected') {
        $date_time_column = "confirmation_date";
        $date_time_coumn2 = "date_inspected";
    }

    $sql = "DECLARE @Year INT = ?;  -- Specify the year
            DECLARE @Month INT = ?;   -- Specify the month (November)

            WITH DateRange AS (
                SELECT 
                    DATEADD(DAY, number, DATEFROMPARTS(@Year, @Month, 1)) AS report_date
                FROM 
                    master.dbo.spt_values
                WHERE 
                    type = 'P' AND 
                    number < DAY(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)))  -- Generate dates for the month
            ),
            FilteredApplicatorHistory AS (
                SELECT 
                    aioh.applicator_no,
                    CAST(aioh.$date_time_column AS DATETIME2(2)) AS $date_time_coumn2
                FROM 
                    t_applicator_in_out_history aioh
                LEFT JOIN 
                    m_applicator a ON aioh.applicator_no = a.applicator_no 
                WHERE 
                    aioh.$date_time_column >= DATEADD(HOUR, 6, CAST(DATEFROMPARTS(@Year, @Month, 1) AS DATETIME2)) AND 
                    aioh.$date_time_column < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)) AS DATETIME2)))  -- Adjusted to include the entire month
                    AND a.car_maker = ? AND a.car_model = ?
            )

            SELECT 
                CAST(dr.report_date AS DATE) AS report_date,  -- Label the report date as DATE
                COUNT(fah.applicator_no) AS total
            FROM 
                DateRange dr
            LEFT JOIN 
                FilteredApplicatorHistory fah ON 
                    fah.$date_time_coumn2 >= DATEADD(HOUR, 6, CAST(dr.report_date AS DATETIME2)) AND 
                    fah.$date_time_coumn2 < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(dr.report_date AS DATETIME2)))  -- Adjusted to ensure the range is from 6 AM to just before 6 AM the next day
            GROUP BY 
                dr.report_date
            ORDER BY 
                dr.report_date";

    $stmt = $conn->prepare($sql);
    $params = array($year, $month, $car_maker, $car_model);
    $stmt->execute($params);

    // Get the number of days in the specified month and year
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    // Initialize an array to hold the counts for the specified status
    $statusCounts = array_fill(0, $daysInMonth, 0); // Assuming you want to initialize for 30 days

    // Fetch results and populate the terminalData array
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Add unique report_date to categories
        if (!in_array($row['report_date'], $categories)) {
            $categories[] = $row['report_date'];
        }

        // Update the count for the specified status
        $dateIndex = array_search($row['report_date'], $categories);
        if ($dateIndex !== false) {
            $statusCounts[$dateIndex] += intval($row['total']); // Use total for counts
        }
    }

    // Create the final data structure
    $data[] = [
        'name' => $status,
        'data' => $statusCounts
    ];

    // Encode the categories and data as JSON
    echo json_encode(['categories' => $categories, 'data' => $data]);
}

if ($method == 'get_month_amd_chart') {
    $year = $_GET['year'];
    $month = $_GET['month'];
    $between = $_GET['between'];

    $data = [];
    $categories = [];
    $averageElapsedTimes = [];
    $maxElapsedTimes = [];

    $sql = "";
    $date_time_column = "";
    $date_time_coumn2 = "";

    if ($between == 1) {
        $date_time_column = "date_time_out";
        $date_time_coumn2 = "date_time_in";
    } else if ($between == 2) {
        $date_time_column = "date_time_in";
        $date_time_coumn2 = "confirmation_date";
    }

    $sql = "DECLARE @Year INT = ?;  -- Specify the year
            DECLARE @Month INT = ?;   -- Specify the month (November)

            WITH AverageData AS (
                SELECT 
                    b.car_maker,
                    b.car_model,
                    AVG(DATEDIFF(MINUTE, $date_time_column, $date_time_coumn2)) AS ave,
                    MAX(DATEDIFF(MINUTE, $date_time_column, $date_time_coumn2)) AS max_diff,
                    STDEV(DATEDIFF(MINUTE, $date_time_column, $date_time_coumn2)) AS std
                FROM 
                    t_applicator_in_out_history AS a 
                LEFT JOIN 
                    m_applicator AS b ON a.applicator_no = b.applicator_no
                WHERE
                    a.$date_time_column >= DATEADD(HOUR, 6, CAST(DATEFROMPARTS(@Year, @Month, 1) AS DATETIME2)) AND 
                    a.$date_time_column < DATEADD(HOUR, 6, DATEADD(DAY, 1, CAST(EOMONTH(DATEFROMPARTS(@Year, @Month, 1)) AS DATETIME2)))
                GROUP BY b.car_maker, b.car_model
            )

            SELECT 
                car_maker,
                car_model,
                ave,
                -- Use the ave column for ave_elapsed_time
                CASE 
                    WHEN ave < 1 THEN '< 1 min' 
                    ELSE 
                        LTRIM(
                            CASE 
                                WHEN ave / 1440 > 0 THEN 
                                    CAST(ave / 1440 AS VARCHAR(10)) + ' day' + 
                                    CASE WHEN ave / 1440 <> 1 THEN 's' ELSE '' END + 
                                    CASE WHEN (ave % 1440) / 60 > 0 OR ave % 60 > 0 THEN ', ' ELSE '' END
                                ELSE '' 
                            END +
                            CASE 
                                WHEN (ave % 1440) / 60 > 0 THEN 
                                    CAST((ave % 1440) / 60 AS VARCHAR(10)) + ' hour' + 
                                    CASE WHEN (ave % 1440) / 60 <> 1 THEN 's' ELSE '' END + 
                                    CASE WHEN ave % 60 > 0 THEN ', ' ELSE '' END
                                ELSE '' 
                            END +
                            CASE 
                                WHEN ave % 60 > 0 THEN 
                                    CAST(ave % 60 AS VARCHAR(10)) + ' min' + 
                                    CASE WHEN ave % 60 <> 1 THEN 's' ELSE '' END 
                                ELSE '' 
                            END
                        ) 
                END AS ave_elapsed_time,
                max_diff,
                -- Use the ave column for ave_elapsed_time
                CASE 
                    WHEN max_diff < 1 THEN '< 1 min' 
                    ELSE 
                        LTRIM(
                            CASE 
                                WHEN max_diff / 1440 > 0 THEN 
                                    CAST(max_diff / 1440 AS VARCHAR(10)) + ' day' + 
                                    CASE WHEN max_diff / 1440 <> 1 THEN 's' ELSE '' END + 
                                    CASE WHEN (max_diff % 1440) / 60 > 0 OR ave % 60 > 0 THEN ', ' ELSE '' END
                                ELSE '' 
                            END +
                            CASE 
                                WHEN (max_diff % 1440) / 60 > 0 THEN 
                                    CAST((max_diff % 1440) / 60 AS VARCHAR(10)) + ' hour' + 
                                    CASE WHEN (max_diff % 1440) / 60 <> 1 THEN 's' ELSE '' END + 
                                    CASE WHEN max_diff % 60 > 0 THEN ', ' ELSE '' END
                                ELSE '' 
                            END +
                            CASE 
                                WHEN max_diff % 60 > 0 THEN 
                                    CAST(max_diff % 60 AS VARCHAR(10)) + ' min' + 
                                    CASE WHEN max_diff % 60 <> 1 THEN 's' ELSE '' END 
                                ELSE '' 
                            END
                        ) 
                END AS max_diff_elapsed_time,
                std
            FROM 
                AverageData
            ORDER BY max_diff DESC";

    $stmt = $conn->prepare($sql);
    $params = array($year, $month);
    $stmt->execute($params);

    // Fetch results and populate the terminalData array
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $maker_model_label = '';

        if ($row['car_maker'] != $row['car_model']) {
            $maker_model_label = $row['car_maker'] . " " . $row['car_model'];
        } else {
            $maker_model_label = $row['car_maker'];
        }

        // Add unique report_date to categories
        if (!in_array($maker_model_label, $categories)) {
            $categories[] = $maker_model_label;
        }

        // Add average and max values to data
        $data['Average'][] = (float)$row['ave'];
        $data['Max'][] = (float)$row['max_diff'];
        $averageElapsedTimes[] = $row['ave_elapsed_time'];
        $maxElapsedTimes[] = $row['max_diff_elapsed_time'];
    }

    // Create the final data structure
    $finalData = [
        'categories' => $categories,
        'data' => [
            [
                'name' => 'Average',
                'data' => $data['Average'],
                'elapsed_time' => $averageElapsedTimes // Add elapsed time for average
            ],
            [
                'name' => 'Max',
                'data' => $data['Max'],
                'elapsed_time' => $maxElapsedTimes // Add elapsed time for max
            ]
        ]
    ];

    // Encode the categories and data as JSON
    echo json_encode($finalData);
}

$conn = null;
