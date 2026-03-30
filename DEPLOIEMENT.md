# Déploiement — Module /crufiture

## 1. Base de données
Importer `schema_crufiture_v1.sql` dans phpMyAdmin (base `u191509486_dbboutique`).

## 2. Variable d'environnement
Ajouter dans `public_html/.env` :
```env
CRUFITURE_FOLDER=/crufiture
```

## 3. Backend PHP
Copier le dossier `api/` dans `public_html/crufiture/api/`.
Structure attendue :
```
public_html/crufiture/api/
├── bootstrap.php
├── index.php
├── routes/api.php
└── controllers/
    ├── PingController.php
    ├── FermeWidgetController.php
    └── DashboardController.php
```
Test immédiat : `https://peyrounet.com/crufiture/api/ping`
→ doit retourner `{"message":"pong","status":"success"}`

## 4. Règles .htaccess racine
Ajouter dans `public_html/.htaccess` :
```apache
# API crufiture
RewriteCond %{REQUEST_URI} ^/crufiture/api
RewriteRule ^crufiture/api/(.*)$ /crufiture/api/index.php [L,QSA]

# SPA crufiture
RewriteCond %{REQUEST_URI} ^/crufiture/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/favicon\.ico$
RewriteCond %{REQUEST_URI} !^/crufiture/assets/.*$
RewriteCond %{REQUEST_URI} !^/crufiture/images(/.*)?$
RewriteRule ^crufiture/ /crufiture/index.html [L]
```
⚠️ Pas de .htaccess dans /crufiture/ ni /crufiture/api/.

## 5. Frontend Vue
```bash
npm install
npm run build
```
Copier le contenu de `dist/` dans `public_html/crufiture/`.
(pas dans un sous-dossier — directement à la racine de /crufiture/)

## 6. Enregistrement dans /peyrounet
Aller dans `/peyrounet/dashboard/parametres/modules` et créer :
- slug : `crufiture`
- libelle : `Crufiture`
- description : `Production de crufiture — gestion des lots, saveurs, recettes`

→ Le module apparaît automatiquement dans le cockpit `/ferme`.

## 7. Vérification finale
- [ ] `GET /crufiture/api/ping` → pong
- [ ] `GET /crufiture/api/ferme-widget` → JSON avec module="crufiture"
- [ ] `https://peyrounet.com/crufiture/` → page de login
- [ ] Login avec compte admin → dashboard (KPIs à zéro, c'est normal)
- [ ] Widget visible dans `/ferme/dashboard`
