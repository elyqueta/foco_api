<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Foco API Docs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="https://cdn.jsdelivr.net/npm/@scalar/scalar@1.32.79/dist/scalar.js"></script>
</head>
<body>
    <div id="scalar"></div>
    <script>
        window.scalar = new Scalar({
            url: "/api/v1/docs.json",
            mountEl: document.getElementById("scalar"),
            layout: "classic",
            darkMode: "no-preference",
        });
    </script>
</body>
</html>
