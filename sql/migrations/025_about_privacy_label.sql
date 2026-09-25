UPDATE fixed_pages
SET body = REPLACE(
    body,
    '・ [Privacy Policy(URL付き)]ページ',
    '・ [Privacy Policy(URL付き)]'
),
updated_at = NOW()
WHERE slug = 'about'
  AND body LIKE '%・ [Privacy Policy(URL付き)]ページ%';

