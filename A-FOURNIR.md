# Fichiers à déposer pour la landing FORMA

Dépose chaque fichier avec **exactement ce nom** : il apparaît tout seul sur la page.
Tant qu'un fichier manque, la page affiche un emplacement en pointillés.

| Fichier | Où il apparaît | Conseil |
|---|---|---|
| `images/aymeric.jpg` et `images/marius.jpg` | Deux ronds en tête du formulaire d'inscription (ordinateur) | Une photo de chacun, visage bien visible, cadrage carré |
| `images/duo-hero.jpg` | Photo horizontale à côté de « À deux, plus d'un million… » (haut de page) | Vous deux sur un chantier, visages bien visibles, format 16:9 |
| `images/duo-animateurs.jpg` | Grand visuel du bloc « Qui sont Aymeric & Marius ? » (sert aussi de couverture à la vidéo) | Vous deux, idéalement sur chantier, format vertical 4:5 |
| `videos/presentation.mp4` | Bloc « Qui sont Aymeric & Marius ? » | 30 à 45 s face caméra sur chantier, format 4:5 : qui vous êtes, vos débuts, pourquoi ce live. Seuls chiffres : le million à deux, 10 000 € dès le 2e mois, un mois à 30 000 €. **Rien sur ce qui sera vendu.** |
| `images/qonto-1.jpg` à `qonto-3.jpg` | Section « Nos chiffres, sans filtre » | Captures Qonto, **noms de clients, IBAN et soldes perso masqués**, format vertical. **Entourer en rouge le total du mois et la période.** 1 = Aymeric 2e mois, 2 = Marius 2e mois, 3 = un des mois à 30 000 € |
| `images/chantier-1.jpg` à `chantier-3.jpg` | Galerie avant / après sous le bloc animateurs | 1 : avant / après toiture · 2 : avant / après façade · 3 : avant / après toiture 2 (photos montées côte à côte), sans adresse ni plaque visible |
| `videos/confirmation.mp4` | Écran après inscription | Vidéo verticale 60 à 90 s (voir `script-video-confirmation.md`) |

## À me donner en texte

- **3 avis Google** de vos clients : le texte, le prénom (ou l'initiale) et la ville si vous voulez. Sans nom de société.
- **Un vrai chantier chiffré** (bloc « Un de nos chantiers ») : surface, montant facturé, coût des produits, temps passé. Tant que les `[crochets]` restent, le bloc ne s'affiche qu'en local, jamais en ligne.
- **Le lien d'invitation du groupe WhatsApp** du live : à coller dans `index.html`, ligne `const WHATSAPP_URL=''`.
