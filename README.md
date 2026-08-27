# Seating App - Backend Symfony

Application complète de gestion de réservation de sièges dans des événements, bâtie avec Symfony 8.1 et utilisant JWT pour l'authentification au lieu de Keycloak.

## Stack Technique

- **PHP** 8.1+
- **Symfony** 8.1
- **PostgreSQL** 16
- **Redis** 7
- **Lexik JWT Authentication** pour la gestion des JWT
- **Doctrine ORM** pour la persistance des données
- **Predis** pour Redis

## Installation

### Prérequis

- PHP 8.1 ou supérieur
- Composer
- Docker & Docker Compose (pour PostgreSQL et Redis)

### Étapes d'installation

Option rapide (recommandée):

```bash
make bootstrap
```

Option manuelle:

1. **Cloner le projet**
```bash
cd /Users/rtodymic/PhpstormProjects/place/place-symfony
```

2. **Installer les dépendances**
```bash
composer install
```

3. **Démarrer les services Docker**
```bash
docker compose -f docker-compose.yml up -d
```

4. **Créer la base de données**
```bash
php bin/console doctrine:database:create
```

5. **Exécuter les migrations**
```bash
php bin/console doctrine:migrations:migrate
```

6. **Vérifier que les clés JWT existent**
Les clés RSA ont déjà été générées dans `config/jwt/`. Si vous avez besoin de les régénérer:
```bash
openssl genrsa -out config/jwt/private.pem 2048
openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem
```

## Démarrage du serveur

```bash
php -S localhost:8000 -t public
```

L'application sera accessible sur `http://localhost:8000`

## Commandes utiles (Make)

```bash
make bootstrap
make up
make down
make migrate
make serve
make demo-flow
```

## Documentation API

Une fois le serveur démarré, la documentation Swagger/OpenAPI est disponible sur:
- http://127.0.0.1:8000/api/doc
- http://127.0.0.1:8000/api/doc.json

## Scenario E2E (API keys + hold + book)

Le script `scripts/demo_flow.sh` automatise:

1. inscription d'un utilisateur backoffice,
2. login JWT,
3. creation d'une API key `backoffice` et d'une API key `public`,
4. creation d'un chart et d'un event,
5. hold puis book de sieges.

Commande:

```bash
make demo-flow
```

## Architecture de l'application

### Entités principales

- **ApiKey**: Gestion des clés API pour l'authentification machine-to-machine
- **User**: Utilisateurs de l'application authentifiés via JWT
- **Event**: Les événements pour lesquels les sièges peuvent être réservés
- **Chart**: Plans de salle avec définition des sièges
- **EventSeat**: État des sièges pour un événement donné (AVAILABLE, HOLD, BOOKED, CANCELED)
- **Category**: Catégories de sièges (VIP, standard, etc.)

### Authentification

L'application supporte deux types d'authentification:

1. **API Key**: Pour l'authentification machine-to-machine
   - Headers: `X-Api-Key-Id`, `X-Api-Key-Secret`
   - Scope: `BACKOFFICE` ou `PUBLIC`

2. **JWT**: Pour l'authentification utilisateur
   - Header: `Authorization: Bearer <token>`
   - Endpoint de login: `POST /api/auth/login`
   - Endpoint d'enregistrement: `POST /api/auth/register`

### Endpoints principaux

#### API Keys
- `GET /api/api-keys` - Lister les clés API
- `POST /api/api-keys` - Créer une clé API
- `DELETE /api/api-keys/{id}` - Désactiver une clé API

#### Catégories
- `GET /api/categories` - Lister les catégories
- `POST /api/categories` - Créer une catégorie
- `PUT /api/categories/{id}` - Modifier une catégorie
- `DELETE /api/categories/{id}` - Supprimer une catégorie

#### Plans de salle (Charts)
- `GET /api/charts` - Lister les plans
- `POST /api/charts` - Créer un plan
- `PUT /api/charts/{id}` - Modifier un plan
- `PUT /api/charts/{id}/objects` - Mettre à jour les objets du plan
- `DELETE /api/charts/{id}` - Supprimer un plan

#### Événements
- `GET /api/events` - Lister les événements
- `POST /api/events` - Créer un événement
- `GET /api/events/{id}` - Détails d'un événement avec statuts des sièges
- `PUT /api/events/{id}` - Modifier un événement
- `DELETE /api/events/{id}` - Supprimer un événement
- `POST /api/events/{id}/link-chart/{chartId}` - Lier un plan de salle à un événement

#### Réservations
- `POST /api/events/{eventId}/hold` - Mettre en attente des sièges (10 min par défaut)
- `POST /api/events/{eventId}/book` - Confirmer la réservation
- `POST /api/events/{eventId}/release` - Libérer les sièges
- `POST /api/events/{eventId}/change-status` - Changer le statut des sièges (BO)

#### Sessions publiques
- `POST /api/sessions` - Créer une session publique pour le widget

#### Authentification
- `POST /api/auth/register` - Créer un compte
- `POST /api/auth/login` - Se connecter
- `GET /api/auth/me` - Récupérer l'utilisateur connecté

## Configuration

Les paramètres de configuration sont définis dans `.env`:

```
DATABASE_URL=postgresql://postgres:postgres@127.0.0.1:5432/place_app
REDIS_URL=redis://127.0.0.1:6379

# JWT
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=votre-passphrase

# Seating
SEATING_HOLD_DURATION_MINUTES=10
SEATING_SESSION_DURATION_MINUTES=60
```

## Structure du code

```
src/
├── Controller/          # Contrôleurs REST
├── Entity/             # Entités Doctrine
├── Repository/         # Repositories Doctrine
├── Service/            # Logique métier
├── Dto/                # Data Transfer Objects
├── Exception/          # Exceptions personnalisées
└── Security/           # Configuration de sécurité
```

## Tests

### Créer une clé API

```bash
curl -X POST http://localhost:8000/api/api-keys \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <jwt-token>" \
  -d '{
    "name": "My API Key",
    "scope": "backoffice"
  }'
```

### Créer un événement

```bash
curl -X POST http://localhost:8000/api/events \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: pk_bo_xxxxx" \
  -H "X-Api-Key-Secret: sk_bo_xxxxx" \
  -d '{
    "title": "Concert 2024",
    "identifier": "concert-2024-01"
  }'
```

### Mettre en attente des sièges

```bash
curl -X POST http://localhost:8000/api/events/{eventId}/hold \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: pk_pub_xxxxx" \
  -H "X-Api-Key-Secret: sk_pub_xxxxx" \
  -d '{
    "seatKeys": ["A1", "A2"],
    "holdToken": "client-session-uuid"
  }'
```

## Notes importantes

- Les holds de sièges expirent après 10 minutes (configurable)
- Les clés Redis sont automatiquement nettoyées lors de l'expiration
- Les migrations Doctrine créent automatiquement les tables
- Les JWT utilisent RSA pour une sécurité renforcée

## Support

Pour toute question ou bug, veuillez ouvrir une issue dans le dépôt.

## Widget de plan de salle (`PlaceRender`)

### Intégration

```html
<script src="https://mitoera.com/mitoera-render.js"></script>
<div id="chart" style="width:100%;height:500px;"></div>
<script>
  const chart = new PlaceRender(document.getElementById('chart'), {
    data:          { chartObjects: [...], categories: [...], seats: [...] },
    showLegend:    true,   // affiche la légende des catégories (défaut: true)
    showResume:    true,   // affiche le résumé des sièges sélectionnés en footer (défaut: true)
    readOnly:      false,
    selectedSeats: [],
    onSeatSelected:   (seat) => console.log('selected', seat),
    onSeatDeselected: (seat) => console.log('deselected', seat),
  });
  chart.render();
</script>
```

### Options

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `data` | `object` | — | Données du plan : `chartObjects`, `categories`, `seats` |
| `showLegend` | `boolean` | `true` | Affiche la barre de légende des catégories en haut du plan |
| `showResume` | `boolean` | `true` | Affiche un footer récapitulatif `● CATÉGORIE — N sièges` dès qu'une sélection existe |
| `readOnly` | `boolean` | `false` | Désactive la sélection des sièges |
| `selectedSeats` | `string[]` | `[]` | Sièges pré-sélectionnés au chargement |
| `onSeatSelected` | `function` | — | Callback appelé à chaque sélection de siège `({ seatKey, catId, catColor, catName })` |
| `onSeatDeselected` | `function` | — | Callback appelé à chaque désélection de siège |

### API publique

| Méthode | Description |
|---------|-------------|
| `render()` | Monte et affiche le widget |
| `selectSeats(keys: string[])` | Sélectionne des sièges par clé (programmatique) |
| `getSelectedObjects()` | Retourne la liste des sièges sélectionnés |
| `updateSeatStatuses(seats)` | Met à jour les statuts des sièges (booked, held…) |
| `reload({ chartObjects, categories, seats })` | Recharge les données sans recréer le widget |
| `destroy()` | Démonte le widget et libère les listeners |

### `showResume`

Footer en bas du plan affichant le nombre de sièges sélectionnés par catégorie.  
Apparaît dès qu'au moins un siège est sélectionné, masqué sinon.

```
● GOLD — 1 siège   ● VIP — 2 sièges   ● VVIP — 2 sièges
```

Dans l'iframe (`render.html`), désactivé par défaut — activer avec le paramètre URL `?resume=1`.

### `showLegend`

Barre en haut du plan listant toutes les catégories avec leur couleur et prix.  
Un clic sur une catégorie filtre les sièges visibles.  
Masquée si aucune catégorie n'est définie.

## Licence

Propriété de Place - Tous droits réservés

