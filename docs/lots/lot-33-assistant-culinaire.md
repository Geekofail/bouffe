# Lot 33 — Assistant culinaire

**Objectif** : adapter une recette en un clic, trouver des idées, compléter un import, poser une
question en cuisine — toujours relu avant d'être enregistré (R34).

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **972 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+15, dont 14 pour l'assistant).
- **50 vérifications dans le navigateur** passent (inchangées : elles tournent sans clé Mistral,
  l'assistant y reste donc caché, comme prévu).
- **Une migration** : la table `assistant_usages` (compteur des demandes et de leur coût). Rien n'est
  modifié dans les données existantes.

Spécification : [08-version-4.md](../08-version-4.md), module 33 et règle R34.

## Mise à jour depuis le lot 36

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-33-assistant
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. L'assistant utilise **la même clé Mistral que les tickets** :
si elle est déjà enregistrée (Paramètres › Tickets de caisse), il est prêt tout de suite, avec un
plafond de **1 € par mois** (Q46). Rien à ajouter dans `.env`.

Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 33 — assistant culinaire"
git push -u origin lot-33-assistant
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 33.1 | **Transformer une recette** | Fiche recette › **Plus › Adapter avec l'assistant…** : version végétarienne, végétalienne, sans lactose, sans gluten, plus rapide, plus légère, pour 2 dans un petit four, ou **autre chose** en 150 caractères. La proposition s'affiche marquée **« Proposé par l'assistant »** : ingrédients remplacés barrés → nouveaux surlignés, ajouts « + … », étapes modifiées surlignées, avertissement éventuel. Trois choix : **Enregistrer comme variante** (lot 19 : seuls les changements d'ingrédients), **Nouvelle recette…** (le brouillon s'ouvre dans l'écran d'import pour être relu), **Jeter** | ✅ |
| 33.2 | **Que faire avec…** | En bas de **Que cuisiner ?** : on tape « courgettes, feta et riz ». Bouffe montre d'abord les **3 recettes du carnet** qui en utilisent le plus (« Avec courgettes, feta (2/3) ») — recherche locale, rien n'est envoyé. Puis, sur demande, **Des idées nouvelles** : trois idées de l'assistant, chacune avec **Importer comme brouillon** | ✅ |
| 33.3 | **Compléter un import** | Dans la relecture d'un import (adresse, texte, photo, brouillon de l'assistant) : **Compléter avec l'assistant** propose temps de préparation, cuisson, repos, difficulté et catégories — **seulement parmi les catégories existantes**. Chaque proposition est une case à cocher ; celles qui remplissent un champ vide sont cochées d'office, celles qui changent une valeur déjà saisie ne le sont pas. Un champ **Difficulté** apparaît aussi dans la relecture | ✅ |
| 33.4 | **Une question sur une étape** | Mode cuisine, sous l'étape : **Une question ?** (« Pas de crème : par quoi la remplacer ? »). Réponse courte (trois phrases au plus), affichée, **jamais enregistrée** dans la recette | ✅ |
| 33.5 | **Coût maîtrisé** | **Paramètres › Assistant** : activer ou non, plafond mensuel **en euros**, clé Mistral (la même que les tickets), consommation du mois par usage et des six derniers mois. Au-delà du plafond, les boutons disparaissent jusqu'au 1er du mois suivant ; une demande qui risquerait de le dépasser est refusée avant envoi | ✅ |
| — | Tests | +14 tests Pest (réponses de Mistral simulées) ; +1 test pour la limite de temps (voir plus bas) | ✅ |

## Ce qui part chez Mistral (R34)

Vérifié par les tests, qui lisent chaque demande simulée :

- **Transformer / Question** : titre, portions, temps, lignes d'ingrédients et étapes de *cette*
  recette, plus la transformation choisie ou la question.
- **Que faire avec…** : la liste tapée, seulement.
- **Compléter un import** : titre, lignes d'ingrédients, étapes en cours de relecture, et les noms
  des catégories du foyer (pour qu'il choisisse parmi elles).
- **Jamais** : noms des membres ou des invités, leurs contraintes, le stock, les dépenses, les notes
  de cuisine. Le contenu des demandes n'est pas conservé dans Bouffe, seulement le type, le nombre
  de jetons et le coût estimé.

## Choix et limites

- **Service** : Mistral Small (`mistral-small-latest`), réponse au format JSON imposé par un schéma
  (mode strict) ; une réponse mal formée est refusée proprement (« réponse incomplète, réessayez »).
  L'interface `CookingAssistant` permet de brancher un autre service plus tard.
- **Coût** : estimé d'après le tarif publié de Mistral Small (0,15 $ par million de jetons envoyés,
  0,60 $ par million reçus), converti à 0,90 € le dollar. Une transformation (environ 2 000 jetons
  envoyés, 1 500 reçus) coûte environ 0,1 centime, une question bien moins ; le plafond de 1 €
  représente donc un millier de demandes au moins. C'est une
  **estimation** : la facture fait foi sur console.mistral.ai.
- **Plafond commun à l'installation** : la clé est payée une seule fois, le plafond compte les
  demandes de tous les foyers. Seul l'administrateur le règle ; les autres voient l'état et la
  consommation.
- **Variante ou nouvelle recette** : une variante (lot 19) ne sait que remplacer ou retirer des
  ingrédients. Si l'assistant en **ajoute**, ou si seules les étapes changent, la proposition ne peut
  devenir qu'une nouvelle recette (le bouton « variante » disparaît et Bouffe dit pourquoi). Les
  étapes réécrites ne sont gardées que dans « Nouvelle recette ».
- **Ingrédient remplaçant inconnu** (« tofu fumé ») : il est créé dans le rayon par défaut, comme à
  l'import. Un nom seulement *approchant* n'est pas repris d'office (« lait d'avoine » ne devient
  pas « Lait »).
- **Saisons** : l'assistant ne les propose pas à l'import (33.3) ; Bouffe les calcule déjà à partir
  des ingrédients (R18).
- **Qui peut s'en servir** : les comptes complets. Un compte « consultation et courses » ne voit pas
  les boutons. Pas sur une recette d'un foyer relié.
- **Limite de temps des pages** : pour laisser le temps au service de répondre, Bouffe allonge la
  limite d'exécution de PHP (déjà le cas pour la lecture des tickets). Elle ne la raccourcit plus
  jamais : `App\Support\TimeLimit` remplace les anciens `set_time_limit()` (tickets, import par
  photo), qui pouvaient couper une commande longue.
- **Adresse du service** : `BOUFFE_ASSISTANT_ENDPOINT` (facultatif, réservé à un relais). Les tests
  l'ignorent : `phpunit.xml` fixe l'adresse, le modèle, le plafond et vide la clé, quel que soit le
  `.env` du poste.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_12_100000_create_assistant_usages.php`, `app/Models/AssistantUsage.php` | Compteur des demandes |
| `app/Services/Assistant/CookingAssistant.php`, `MistralAssistant.php`, `AssistantAnswer.php`, `AssistantFailed.php` | Le service (interface commune, Mistral) |
| `app/Services/Assistant/AssistantService.php` | Disponibilité, plafond, coût, comptage |
| `app/Services/Assistant/RecipeAssistant.php` | Les quatre usages : ce qui est envoyé, schémas, brouillons |
| `app/Livewire/Recipes/AssistantTransform.php` + vue | 33.1 (fenêtre sur la fiche recette) |
| `app/Livewire/Stock/WhatToMake.php` + vue | 33.2 (bloc de « Que cuisiner ? ») |
| `app/Livewire/Recipes/CookQuestion.php` + vue | 33.4 (mode cuisine) |
| `app/Livewire/Settings/AssistantSettings.php` + vue | 33.5 |
| `resources/views/components/assistant-mark.blade.php` | Marque « Proposé par l'assistant » |
| `resources/views/livewire/recipes/import/assistant-complete.blade.php` | 33.3 |
| `app/Support/TimeLimit.php` | Limite de temps sans effet de bord |
| `tests/Feature/Assistant/AssistantTest.php`, `tests/Unit/TimeLimitTest.php` | 14 + 1 tests |

Fichiers modifiés :

- `Recipes\Import` (reprise d'un brouillon de l'assistant, 33.3, champ Difficulté) et sa vue ;
  `RecipeImporter::fromAssistant()` ;
- fiche recette (menu **Plus**), mode cuisine, « Que cuisiner ? » ;
- Paramètres (menu, page d'accueil, page Foyers de l'administrateur), `routes/web.php` ;
- `config/bouffe.php` (section `assistant`), `Settings` (réglages d'installation
  `assistant.enabled`, `assistant.monthly_cap`), `AppServiceProvider` ;
- `HouseholdData` (suppression d'un foyer), `.env.example`, `phpunit.xml` ;
- `Receipts\Show`, `Receipts\Capture` (limite de temps) ;
- un test existant ajusté : le classement des modèles propres à un foyer (34).

## À vérifier sur ton poste

- **Paramètres › Assistant** : « Prêt · Mistral Small ». Sinon, le bandeau dit pourquoi (clé,
  désactivé, plafond).
- **Une fiche recette › Plus › Adapter avec l'assistant…** : « Version végétarienne », lire la
  proposition, **Enregistrer comme variante** ; puis essayer « Autre chose… ».
- **Que cuisiner ?** tout en bas : « courgettes, feta et riz », **Chercher**, puis **Des idées
  nouvelles**, **Importer comme brouillon**, relire, **Enregistrer** ou **Annuler**.
- **Importer une recette** par texte, puis **Compléter avec l'assistant**.
- **Cuisiner** une recette, étape 2, **Une question ?**.
- Revenir sur **Paramètres › Assistant** : les demandes sont comptées.

Les captures du lot ont été faites avec des réponses **simulées** (inventées pour l'occasion) ; les
vraies réponses de Mistral seront formulées autrement.
