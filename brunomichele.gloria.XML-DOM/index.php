<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <title>Portafoglio</title>
  <link rel="stylesheet" href="style.css" type="text/css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="script.js"></script> <!-- dove sta generaGrafico() -->
</head>
<body>
  <div id="titoli">
    <h1>Portafoglio Titoli</h1>
    <?php include __DIR__ . '/load.php'; ?>
  </div>

  <canvas id="graph" width="400" height="400"></canvas>

  <script>
    // la tabella è già nel DOM: richiama il grafico
    document.addEventListener('DOMContentLoaded', () => {
      generaGrafico(10);
    });
  </script>
</body>
</html>