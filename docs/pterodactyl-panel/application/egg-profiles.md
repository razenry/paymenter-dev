# Egg Profiles API Documentation

Egg Profiles are used to group Nests and Eggs for special node types (like Hyper Nodes) to allow or restrict specific environments.

## Base URL
`{{panel_url}}/api/application/nests/egg-profiles`

## Endpoints

### List Egg Profiles
`GET /`

Returns a list of all egg profiles configured on the panel.

#### Example Response
```json
{
  "object": "list",
  "data": [
    {
      "object": "egg_profile",
      "attributes": {
        "id": 1,
        "label": "Standard Virt",
        "eggs": [15, 16],
        "nests": [5],
        "created_at": "2026-04-02T09:00:00+00:00",
        "updated_at": "2026-04-02T09:00:00+00:00"
      }
    },
    {
      "object": "egg_profile",
      "attributes": {
        "id": 2,
        "label": "Premium Virt",
        "eggs": [15, 16, 17, 18],
        "nests": [5, 6],
        "created_at": "2026-04-02T09:30:00+00:00",
        "updated_at": "2026-04-02T09:30:00+00:00"
      }
    }
  ]
}
```
