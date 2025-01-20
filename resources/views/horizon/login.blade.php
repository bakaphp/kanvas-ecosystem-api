<!DOCTYPE html>
<html>
<head>
    <title>Horizon Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.3/css/bulma.min.css">
</head>
<body>
    <section class="section">
        <div class="container">
            <h1 class="title">Horizon Login</h1>
            <form id="loginForm" class="box">
                @csrf
                <div class="field">
                    <label class="label" for="email">Email:</label>
                    <div class="control">
                        <input class="input" type="email" id="email" name="email" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="password">Password:</label>
                    <div class="control">
                        <input class="input" type="password" id="password" name="password" required>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Login</button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            // Define the GraphQL mutation
            const query = `
                mutation login($data: LoginInput!) {
                    login(data: $data) {
                        id
                        token
                        refresh_token
                        token_expires
                        refresh_token_expires
                        time
                        timezone
                    }
                }
            `;

            // Define the variables for the mutation
            const variables = {
                data: {
                    email: email,
                    password: password,
                },
            };

            try {
                const response = await fetch('/graphql', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Kanvas-App': '{{ $app->key }}',
                    },
                    body: JSON.stringify({ query, variables }),
                });

                const data = await response.json();

                console.log('Response:', data);

                if (data.errors) {
                    alert('Login failed: ' + data.errors[0].message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            }
        });
    </script>
</body>
</html>