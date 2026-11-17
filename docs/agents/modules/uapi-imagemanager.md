# UAPI ImageManager

> Agent contract notes for `uapi()->imageManager()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI ImageManager (2026-10-31)

`uapi()->imageManager()` covers all four pinned functions from cPanel 82 with
the WebServer role. `getDimensions(imageFile)` remains GET;
`convertFile(imageFile, type)`, `createThumbnails(dir, heightPercentage,
widthPercentage)` and `resizeImage(height, imageFile, width, saveOriginalAs:
null)` use form POST. Paths follow existing Fileman scalar conventions and are
sent unchanged, encoded once, without local file access, normalization or a new
sensitivity policy. The pinned conversion prose says account `/home` relative;
reviewed cPanel 11.138.0.19 also accepted absolute paths under that home. This
establishes no universal confinement guarantee. The reviewed handler checks type
characters only: `nope` succeeded with a `.nope` extension, without a format
enum. Shared response/request frames retain their sensitive annotations. No new
format enum or range validation is introduced: dimensions are documented
positive and percentages 1 through 99, while scalar forwarding alone does not
establish server acceptance of zero/negative values.

Conversion returns an object with optional string converted_file, dimensions an
object with optional integer height/width, thumbnails a list with optional
integer failed and string file/reason/thumbnail_file, and resize a string
absolute path. All fields are nonnullable. Preserve omitted fields, empty
thumbnail lists and mixed successful/failed rows under status 1; never infer
all-thumbnail success. failed's source singleton enum [1] follows existing
integer lowering, without new flag or enum semantics. Existing legacy
empty-array object compatibility remains. Dimensions accept evidenced canonical
bounded integer strings and return optional integers using the existing signed
integer compatibility policy. Thumbnail status 0 returns the complete validated
nonempty row list, including all-failed reasons; status 0 with an empty list or
explicit null raises `ApiErrorException`. Empty directories may have no error
text (`errors()` is empty). Missing data, wrong roots and malformed known fields
remain payload mismatches with `outcomeConfirmed=false` for thumbnails. Status 1
remains strict and permits an empty list. Other operations retain Flag and
confirmed-outcome mismatches. Thumbnails use a thumbnails subdirectory and tn_
filenames.

Omitted/null saveOriginalAs emits no field and the server saves no original
copy; explicit empty string stays present. The `saveOriginalAs` value must name
a NEW FILE path; the server rejects any existing path, including a directory.
The pinned parameter prose calls it a directory, but reviewed source and
both-route cPanel 11.138.0.19 calls resolve it to a file: `img/orig.png`
retained the original while existing directory `img` failed. Never infer a local
filesystem operation, automatic backup/read/restore, cleanup, collision
behavior, atomicity, retry or rollback. Resize has a pinned clean rollback label
without a defined client restore protocol. This slice ran no live calls;
transformations and HTTP image behavior remain open under 8x0.2.60. Canonical
coverage is 1,154 operations (558 UAPI, 596 WHM), 100 groups and 203 outputs
(202 PHP files plus coverage), including the source-bound root-list rows
extension to existing generator/runtime machinery. Pinned sources, historical
evidence and unrelated generated contracts are unchanged.

Review corrections above are attributed to [PR93 review 5724933500](https://github.com/hostingpal/whm-api-client/pull/93#pullrequestreview-5724933500)
of revision `0898b8b23929b57c233aa3065be8c460aa2f8d23`, submitted
2026-10-31T21:07:45Z. The reviewer removed the disposable account/home and revoked
temporary tokens after both runs. This correction made no server call or capture.
