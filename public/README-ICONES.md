# Icônes PWA — Crufiture

## Pour générer les icônes

1. Partir du fichier `icon-base.svg` fourni (soleil + plateau + jarre, fond vert foncé #0f1b0f)
2. Uploader sur **https://realfavicongenerator.net** — tout est généré automatiquement
3. Récupérer les fichiers et les placer dans `public/icons/` :

| Fichier | Taille | Usage |
|---------|--------|-------|
| `icon-512.png` | 512×512 | Android, splash screen |
| `icon-192.png` | 192×192 | Android, manifest |
| `apple-touch-icon.png` | 180×180 | **iPhone** ← LE PLUS IMPORTANT |
| `apple-touch-icon-167.png` | 167×167 | iPad Retina |
| `apple-touch-icon-152.png` | 152×152 | iPad |
| `apple-touch-icon-120.png` | 120×120 | iPhone non-Retina |
| `favicon-32.png` | 32×32 | Onglet navigateur |
| `favicon-16.png` | 16×16 | Onglet navigateur |
| `../favicon.ico` | multi | Fallback navigateur |

## Déploiement

```
crufiture/
├── favicon.ico
├── manifest.json
└── icons/
    ├── icon-192.png
    ├── icon-512.png
    ├── apple-touch-icon.png
    ├── apple-touch-icon-167.png
    ├── apple-touch-icon-152.png
    ├── apple-touch-icon-120.png
    ├── favicon-32.png
    ├── favicon-16.png
    └── icon-base.svg
```

Ces fichiers vont dans `public/` pendant le développement Vite.
Après `npm run build`, ils sont copiés automatiquement dans `dist/`.
Déployer `dist/` dans `/crufiture/` sur le serveur.

## Note iOS

iOS lit les `apple-touch-icon` depuis les balises `<link>` dans `index.html`,
pas depuis le `manifest.json`. Les deux sont renseignés dans ce projet.

## Note icône maskable (Android)

L'icône maskable doit avoir son contenu dans les 80% centraux (zone safe area).
Le fond doit couvrir tout le carré — pas de transparence.
Le SVG de base est conçu avec cette contrainte en tête.

## Vérification

- https://www.pwabuilder.com — audit PWA complet
- Chrome DevTools → Application → Manifest — vérifie que tout est reconnu
- Sur iPhone : Safari → Partager → Sur l'écran d'accueil
- Sur Android : Chrome → menu ⋮ → Installer l'application
