# WordPress.org assets

These are the repository listing images, not plugin runtime files. WordPress.org
reads them from the `assets/` directory at the root of the plugin's SVN repo, not
from inside the plugin folder. Most deploy actions (e.g.
`10up/action-wordpress-plugin-deploy`) look in `.wordpress-org/` by default and
copy the contents across to SVN `assets/`.

**This folder belongs in the git repository** — the deploy step reads it from
there. It is kept out of the *installable plugin* by `.distignore` (for
`wp dist-archive` and the deploy action) and `.gitattributes` `export-ignore`
(for `git archive` and GitHub release tarballs). The leading dot alone does
nothing; git tracks dot-folders like any other.

| File | Used for |
|---|---|
| `banner-772x250.png` | Plugin page header |
| `banner-1544x500.png` | Same, high-DPI |
| `icon-128x128.png` | Search results and the plugin card |
| `icon-256x256.png` | Same, high-DPI |

Regenerate with `python generate-assets.py` (requires Pillow).

## A note on the artwork

The mark is an original book-with-a-pulse, drawn for this plugin. It deliberately
does **not** use the MasterStudy "MS" logo. That logo is StyleMix Themes'
trademark, and putting it on this plugin's listing would imply an official
affiliation the plugin explicitly disclaims in its header and readme — which is
also grounds for rejection under WordPress.org's plugin guidelines.

Echoing MasterStudy's blue and general layout is fine; reproducing their logo is not.

Palette: `#3B5BDB` primary, `#2F49AF` shade, `#D6DFFF` secondary text.
