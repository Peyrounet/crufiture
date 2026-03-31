# TODO — Module /crufiture
**Mis à jour : 31 mars 2026**

---

## Fait

- ✅ Corriger double flèche menu "Retour ferme"
- ✅ Layout simulateur responsive (CSS grid natif + inputClass sim-input)
- ✅ **Gestion des saveurs** — CRUD `cruf_saveur` + liste cards avec avatar + recherche live

---

## Fonctionnalités à développer (ordre suggéré)

- [ ] **Gestion des recettes** — CRUD `cruf_recette` + `cruf_recette_ingredient` (instructions + ingrédients avec pct_base)
- [ ] **Création d'un lot** — formulaire saisie + calculs Krencker en temps réel (réutiliser logique simulateur) + `cruf_lot_fruit`
- [ ] **Suivi évaporation** — saisie mobile heure/poids/météo → `cruf_releve_evaporation`
- [ ] **Mise en pot** — saisie poids réel + répartition en jarres → `cruf_jarre`
- [ ] **Contrôles qualité** — saisie Brix, Aw, pH → `cruf_controle` (plusieurs par lot)
- [ ] **Prix de revient** — appel `POST /peyrounet/api/inter/prix-revient` depuis un lot
- [ ] **Calcul à rebours** — dès 2ème lot de la saveur, règle de 3 sur rendements historiques
- [ ] **Conditionnement** — coût vrac / pot 100g / pot 300g selon emballage
- [ ] **Canaux de distribution** — marge et facturation par canal (revendeur, direct, boutique)