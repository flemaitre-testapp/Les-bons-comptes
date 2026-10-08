# Budget commun

Petite application web pour suivre les dépenses communes entre deux personnes : qui a payé quoi, la part de chacun, les remboursements, et le solde à date. Chaque action est tracée dans un journal scellé, visible par les deux.

PHP 8 + SQLite. Aucune base MySQL à créer, aucune dépendance. Prévu pour un hébergement mutualisé o2switch (cPanel).

## Fonctionnement

- **Deux comptes** : un administrateur (toi) et un membre.
- **Dépense** : libellé, montant, date, catégorie, qui a payé, répartition (règle habituelle, 50/50, 100 % l'un ou l'autre, ou personnalisée), justificatif photo/PDF.
- **Remboursement** : virement ou espèces de l'un vers l'autre pour solder.
- **Validation** : chaque opération saisie par l'un doit être validée ou contestée (avec motif obligatoire) par l'autre. Le solde « hors contestations » est affiché à part.
- **Aucune suppression ni modification silencieuse** : une erreur se corrige par « Corriger » (nouvelle version, l'ancienne reste visible, barrée, avec la raison) ou « Annuler » (raison obligatoire).
- **Journal** : connexions, ajouts, validations, contestations, annulations, changements de mot de passe... Chaque ligne contient l'empreinte de la précédente, et chaque opération garde l'empreinte de son contenu d'origine. Toute modification directe de la base est détectée et affichée aux deux.
- **Charges récurrentes** (admin) : nounou, épargne, assurances... ajoutées en un clic chaque mois.
- **Relevé** imprimable / PDF sur une période + exports CSV (opérations et journal).
- **Admin** : répartition par défaut, catégories, charges récurrentes, renommage, réinitialisation du mot de passe de l'autre compte (tracée et signalée).

## Installation sur o2switch

### 1. Pousser le code sur GitHub

```bash
cd budget-commun
git remote add origin git@github.com:TON-COMPTE/budget-commun.git
git push -u origin main
```

Mets le dépôt en **privé**.

### 2. Cloner dans cPanel

cPanel > **Git Version Control** > Create :
- Clone URL : l'URL du dépôt (pour un dépôt privé, ajoute d'abord la clé SSH du serveur dans GitHub > Settings > Deploy keys, comme pour La Boucle)
- Repository Path : `repositories/budget-commun`

### 3. Déployer

Dans le dépôt cloné : onglet **Pull or Deploy** > **Deploy HEAD Commit**.

Le script `deploy.sh` :
- copie l'appli dans `~/public_html/budget`
- crée `~/budget-data` (base + justificatifs), **hors du dossier web**
- crée `config.local.php` une seule fois

Pour changer le dossier (sous-domaine dédié par exemple), modifie `APP_DIR` dans `deploy.sh`.

### 4. HTTPS

cPanel > SSL/TLS Status : vérifier que le certificat AutoSSL couvre le domaine ou sous-domaine. Le `.htaccess` force le HTTPS.

### 5. Première connexion

Ouvre `https://ton-domaine/budget/`. La page **Installation** s'affiche une seule fois : crée ton compte admin et le second compte avec un mot de passe provisoire. À sa première connexion, l'autre personne devra choisir son propre mot de passe.

Fais-le juste après le déploiement : tant qu'aucun compte n'existe, cette page est accessible.

### Mises à jour

`git push`, puis dans cPanel : Update from Remote, puis Deploy HEAD Commit. Les données ne sont jamais touchées.

## Sauvegardes

- Admin > Réglages : exports CSV des opérations et du journal.
- Recommandé : chaque mois, imprimer le relevé en PDF et l'envoyer par mail à l'autre partie. C'est ce document daté, reçu par les deux, qui a le plus de poids en cas de désaccord.
- Le dossier `~/budget-data` est inclus dans les sauvegardes JetBackup d'o2switch.

## Sécurité

- Mots de passe hachés (bcrypt), 10 caractères minimum
- Blocage 15 min après 6 échecs de connexion
- Jetons CSRF, cookies HttpOnly / SameSite / Secure, en-têtes de sécurité, CSP
- Justificatifs servis uniquement aux utilisateurs connectés
- Base et code inaccessibles depuis le web

## Développement local

```bash
php -S 127.0.0.1:8000
```
