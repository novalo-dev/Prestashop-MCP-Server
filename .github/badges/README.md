# Badges

The README badges, generated as local SVG files rather than served by
shields.io, so the README depends on nothing outside the repository.

The exceptions are the badges whose value changes without a commit here, so a
file in the repository could not follow them. The PHP tests, Integration and
Security badges are served by GitHub Actions and show the last run of each
workflow on develop. The release badge, stars, forks and contributors are
served by shields.io, which reads them from GitHub. The tools and prompts
badges are counted from the code by the Badges workflow on every push to
develop (`check-tools.php --badges`), published on the badges branch and drawn
by shields.io, so no sentence has to be updated when a tool is added.

The PrestaShop and PHP badges state facts about the module: change them in the
same pull request as `ps_versions_compliancy` or `composer.json`.

## Regenerate

From the repository root:

```bash
npm i simple-icons
python3 .github/badges/generate.py
```

Needs Python 3 with Pillow and the DejaVu Sans font. Edit the `BADGES` table at
the bottom of `generate.py` to change a label, a message or a colour. Logos come
from `simple-icons`: the `logo` key is a file name in
`node_modules/simple-icons/icons/`.

Other paths can be given with `SIMPLE_ICONS=/path/to/icons` and
`BADGE_FONT=/path/to/font.ttf`.
