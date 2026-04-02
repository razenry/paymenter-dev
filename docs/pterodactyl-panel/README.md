# Pterodactyl API Documentation

This directory contains REST HTTP examles for the Pterodactyl API. These examples are designed to be used with the [REST Client](https://marketplace.visualstudio.com/items?itemName=humao.rest-client) extension for Visual Studio Code, or similar tools.

## Structure

- [Application API](./application/README.md): Admin-level operations for managing users, nodes, servers, etc.
- [Client API](./client/README.md): Operations for the end-user to manage their own servers and account.

## How to Use

1. Create a `.env` file in this directory or use a `.vscode/settings.json` to define the following variables:
   - `panel_url`: The URL of your Pterodactyl panel (e.g., `https://panel.example.com`).
   - `application_api_key`: An API key generated from the Admin Panel.
   - `client_api_key`: An API key generated from the User Settings.
   - `server_id`: The UUID of the server you want to test with (for Client API).

2. Open any of the `.http` files and click on the "Send Request" link above each request.

## Endpoints

### Application API
- [Users](./application/users.http)
- [Nodes](./application/nodes.http)
- [Servers](./application/servers.http)

### Client API
- [Account](./client/account.http)
- [Servers](./client/servers.http)
