# Lot 3 — Planning de la semaine

**Objectif** : organiser les repas de la semaine (recettes, restes, repas libres) pour préparer la liste de courses du lot 4.

**Statut** : ✅ développé et vérifié en local (217 tests passés sous SQLite **et** MariaDB 10.11, parcours complet dans un navigateur : ordinateur 1440/1280 px, tablette 1024/768 px, mobile 390 px) — ⏳ à valider sur ton poste.

## Mise à jour depuis le lot 2

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # table planned_meals
php artisan view:clear
php artisan test         # 217 tests
```

Aucune nouvelle donnée de départ. Réglage facultatif dans `.env` : `BOUFFE_HOUSEHOLD_SIZE=2` (nombre de personnes à chaque repas).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 3.1 | Modèle de données | Table `planned_meals` (date, créneau, position, type, recette, restes de, texte libre, portions, commentaire, mangé le) ; enum `MealType` | ✅ |
| 3.2 | Service `WeekPlanner` | Toutes les règles du planning, testées : semaines du lundi, ajout, restes, déplacement, duplication, suppression, copie et vidage de semaine, suggestions | ✅ |
| 3.3 | Grille de la semaine | 7 jours × créneaux actifs ; aujourd'hui mis en évidence ; jours passés grisés ; navigation semaine précédente / suivante / « Cette semaine » (semaine conservée dans l'URL) | ✅ |
| 3.4 | Affichage adaptatif | Ordinateur (≥ 1280 px) : grille alignée ; tablette : cartes par jour sur 2 colonnes ; téléphone : un jour par carte | ✅ |
| 3.5 | Ajouter un repas | Fenêtre à 3 onglets : **Recette** (recherche titre/ingrédient, filtres par catégorie, nombre de portions, suggestions « pas mangées depuis longtemps »), **Restes** (repas des 7 derniers jours avec portions restantes), **Repas libre** (texte + raccourcis Restaurant, Chez la famille, Pizza…) | ✅ |
| 3.6 | Plusieurs éléments par case | ex. plat + fromage et dessert ; ordre modifiable | ✅ |
| 3.7 | Glisser-déposer | Déplacer un repas vers n'importe quelle case de la semaine ; les déplacements incohérents (restes avant le repas d'origine) sont refusés avec un message | ✅ |
| 3.8 | Assistant restes | Après avoir planifié une recette avec plus de portions que de convives (ex. 4 pour 2) : « Il reste 2 portions. Placer les restes demain midi ? » en un clic ; aussi disponible depuis le détail du repas | ✅ |
| 3.9 | Détail d'un repas | Portions, commentaire, **« mangé ✓ »**, lien vers la recette, restes planifiés, duplication vers un autre jour, retrait (avec ses restes) | ✅ |
| 3.10 | Semaine entière | **Copier** vers une des 8 semaines suivantes (ajouter ou remplacer ; les restes suivent leurs repas) ; **Vider** la semaine | ✅ |
| 3.11 | Fiche recette | Bouton **Planifier** (date, créneau, portions) ; « Mangée 3 fois, dernière fois le… » et « Prévue jeudi 24 septembre » | ✅ |
| 3.12 | Liste des recettes | Nouveau tri **« Pas mangées depuis longtemps »** (jamais planifiées en premier) | ✅ |
| 3.13 | Tableau de bord | **Au menu aujourd'hui** par créneau (cocher « mangé »), aperçu de demain, avancement de la semaine (x / 14 repas planifiés) | ✅ |
| 3.14 | Protections | Recette planifiée : suppression refusée (archiver à la place) ; créneau utilisé : suppression refusée (désactiver à la place) ; repas sur créneau désactivé signalés sous la grille | ✅ |
| 3.15 | Navigation tablette | Barre de menu en icônes seules entre 768 et 1024 px (les libellés débordaient) | ✅ |
| 3.16 | Tests | +27 tests (service et écrans) — 217 au total, exécutés aussi sur MariaDB | ✅ |

## Règles de gestion implémentées

- **Semaine** : du lundi au dimanche.
- **Portions proposées** : `BOUFFE_HOUSEHOLD_SIZE` (2 par défaut).
- **Restes disponibles** = portions cuisinées − portions mangées au repas (taille du foyer) − restes déjà placés. Exemple : lasagnes pour 6 → 2 mangées → 4 portions de restes, soit deux repas.
- Des restes ne peuvent pas être placés **avant** leur repas d'origine ; un repas ne peut pas être déplacé **après** ses restes.
- Réduire les portions d'un repas en dessous de ce qu'exigent ses restes déjà placés est refusé.
- **Retirer un repas retire ses restes** ; **vider une semaine** retire aussi les restes de ces repas placés la semaine suivante.
- **Copier une semaine** : même jour et même créneau, portions et commentaires conservés, « mangé » remis à zéro ; les restes dont le repas d'origine est hors de la semaine copiée ne sont pas copiés.
- **Suggestions** : recettes actives, non prévues dans la semaine, jamais planifiées d'abord puis les plus anciennes.
- **Repas libres et restes** n'alimenteront pas la liste de courses (lot 4).

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/planning?semaine=AAAA-MM-JJ` | `planner.week` | `Planner\Week` (+ `Planner\MealPicker`) |

## Choix faits pendant le lot

- **Une seule grille HTML pour tous les écrans**, grâce à CSS *subgrid* : les cases s'alignent d'un jour à l'autre sur ordinateur et se réorganisent en cartes sur mobile, sans dupliquer le balisage (important pour le glisser-déposer Livewire).
- **Sélecteur de repas en composant Livewire séparé** (`MealPicker`) qui prévient la grille par événement : la grille reste légère et le sélecteur réutilisable.
- **Règles dans `WeekPlanner`** : les composants ne font qu'appeler le service ; une règle non respectée devient un message à l'écran.
- **Tests exécutés aussi sur MariaDB** en plus de SQLite, pour éviter les différences SQL entre la base de test et Wamp.

## Prochain lot

**Lot 4 — Liste de courses** : génération depuis le planning (quantités mises à l'échelle, converties et additionnées, triées par rayon), produits de base « à vérifier », articles manuels et récurrents, provenance, cases à cocher sur téléphone, régénération, impression et copie texte.
