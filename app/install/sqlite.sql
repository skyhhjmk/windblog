-- SQLite数据库初始化脚本

-- 创建用户表
CREATE TABLE IF NOT EXISTS wa_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  nickname TEXT NOT NULL,
  password TEXT NOT NULL,
  sex TEXT NOT NULL DEFAULT '1',
  avatar TEXT DEFAULT NULL,
  email TEXT DEFAULT NULL,
  mobile TEXT DEFAULT NULL,
  level INTEGER NOT NULL DEFAULT 0,
  birthday DATE DEFAULT NULL,
  money REAL NOT NULL DEFAULT 0.00,
  score INTEGER NOT NULL DEFAULT 0,
  last_time DATETIME DEFAULT NULL,
  last_ip TEXT DEFAULT NULL,
  join_time DATETIME DEFAULT NULL,
  join_ip TEXT DEFAULT NULL,
  token TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  role INTEGER NOT NULL DEFAULT 1,
  status INTEGER NOT NULL DEFAULT 0
);

-- 创建分类表
CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT DEFAULT NULL,
  parent_id INTEGER DEFAULT NULL,
  sort_order INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  status INTEGER NOT NULL DEFAULT 1,
  deleted_at DATETIME DEFAULT NULL,
  FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
);

-- 创建文章表
CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  content_type TEXT NOT NULL DEFAULT 'markdown',
  content TEXT NOT NULL,
  excerpt TEXT DEFAULT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  visibility TEXT NOT NULL DEFAULT 'public',
  password TEXT DEFAULT NULL,
  featured INTEGER NOT NULL DEFAULT 0,
  allow_comments INTEGER NOT NULL DEFAULT 1,
  comment_count INTEGER NOT NULL DEFAULT 0,
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  CHECK (content_type IN ('markdown', 'html', 'text', 'visual')),
  CHECK (status IN ('draft', 'published', 'archived')),
  CHECK (visibility IN ('public', 'private', 'password'))
);

CREATE INDEX IF NOT EXISTS idx_posts_featured ON posts(featured);
CREATE INDEX IF NOT EXISTS idx_posts_visibility ON posts(visibility);
CREATE INDEX IF NOT EXISTS idx_posts_allow_comments ON posts(allow_comments);

-- 创建文章-分类关联表
CREATE TABLE IF NOT EXISTS post_category (
  post_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, category_id),
  FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
);

-- 创建文章-作者关联表
CREATE TABLE IF NOT EXISTS post_author (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id INTEGER NOT NULL,
  author_id INTEGER DEFAULT NULL,
  is_primary INTEGER NOT NULL DEFAULT 0,
  contribution TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (post_id, author_id),
  FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES wa_users (id) ON DELETE CASCADE
);

-- 创建友链表
CREATE TABLE IF NOT EXISTS links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  description TEXT DEFAULT NULL,
  icon TEXT DEFAULT NULL,
  image TEXT DEFAULT NULL,
  sort_order INTEGER DEFAULT 0,
  status INTEGER NOT NULL DEFAULT 1,
  target TEXT DEFAULT '_blank',
  redirect_type TEXT NOT NULL DEFAULT 'info',
  show_url INTEGER NOT NULL DEFAULT 1,
  content TEXT DEFAULT NULL,
  email TEXT DEFAULT NULL,
  callback_url TEXT DEFAULT NULL,
  note TEXT DEFAULT NULL,
  seo_title TEXT DEFAULT NULL,
  seo_keywords TEXT DEFAULT NULL,
  seo_description TEXT DEFAULT NULL,
  custom_fields TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL
);

-- 创建浮动链接表（FloLink）
CREATE TABLE IF NOT EXISTS flo_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  keyword TEXT NOT NULL,
  url TEXT NOT NULL,
  title TEXT DEFAULT NULL,
  description TEXT DEFAULT NULL,
  image TEXT DEFAULT NULL,
  priority INTEGER DEFAULT 100,
  match_mode TEXT DEFAULT 'first' CHECK (match_mode IN ('first', 'all')),
  case_sensitive INTEGER DEFAULT 0,
  replace_existing INTEGER DEFAULT 1,
  target TEXT DEFAULT '_blank',
  rel TEXT DEFAULT 'noopener noreferrer',
  css_class TEXT DEFAULT 'flo-link',
  enable_hover INTEGER DEFAULT 1,
  hover_delay INTEGER DEFAULT 200,
  status INTEGER DEFAULT 1,
  sort_order INTEGER DEFAULT 999,
  custom_fields TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL
);

CREATE INDEX idx_flo_links_keyword ON flo_links(keyword);
CREATE INDEX idx_flo_links_status ON flo_links(status);
CREATE INDEX idx_flo_links_priority ON flo_links(priority);
CREATE INDEX idx_flo_links_sort_order ON flo_links(sort_order);

-- 创建页面表
CREATE TABLE IF NOT EXISTS pages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  content TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  template TEXT DEFAULT NULL,
  sort_order INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL
);

-- 创建网站设置表
CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL UNIQUE,
  value TEXT DEFAULT NULL,
  "group" TEXT DEFAULT 'general',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 创建媒体附件表
CREATE TABLE IF NOT EXISTS media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL,
  original_name TEXT NOT NULL,
  file_path TEXT NOT NULL,
  thumb_path TEXT DEFAULT NULL,
  file_size INTEGER NOT NULL DEFAULT 0,
  mime_type TEXT NOT NULL,
  alt_text TEXT DEFAULT NULL,
  caption TEXT DEFAULT NULL,
  description TEXT DEFAULT NULL,
  author_id INTEGER DEFAULT NULL,
  author_type TEXT DEFAULT 'user',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL
);

-- 创建导入任务表
CREATE TABLE IF NOT EXISTS import_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL,
  file_path TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  options TEXT DEFAULT NULL,
  progress INTEGER NOT NULL DEFAULT 0,
  message TEXT DEFAULT NULL,
  author_id INTEGER DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
  FOREIGN KEY (author_id) REFERENCES wa_users (id) ON DELETE SET NULL
);

-- 创建评论表
CREATE TABLE IF NOT EXISTS comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id INTEGER NOT NULL,
  user_id INTEGER DEFAULT NULL,
  parent_id INTEGER DEFAULT NULL,
  guest_name TEXT DEFAULT NULL,
  guest_email TEXT DEFAULT NULL,
  content TEXT NOT NULL,
  quoted_data TEXT DEFAULT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  ip_address TEXT DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  CHECK (status IN ('pending', 'approved', 'spam', 'trash')),
  FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES wa_users (id) ON DELETE SET NULL,
  FOREIGN KEY (parent_id) REFERENCES comments (id) ON DELETE SET NULL
);

-- 创建标签表
CREATE TABLE IF NOT EXISTS tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL
);

-- 创建文章-标签关联表
CREATE TABLE IF NOT EXISTS post_tag (
  post_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, tag_id),
  FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

-- 创建管理员角色关联表
CREATE TABLE IF NOT EXISTS wa_admin_roles (
  role_id INTEGER NOT NULL,
  admin_id INTEGER NOT NULL,
  UNIQUE (role_id, admin_id)
);

-- 创建管理员表
CREATE TABLE IF NOT EXISTS wa_admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  nickname TEXT NOT NULL,
  password TEXT NOT NULL,
  avatar TEXT DEFAULT '/app/admin/avatar.png',
  email TEXT DEFAULT NULL,
  mobile TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  login_at DATETIME DEFAULT NULL,
  status INTEGER DEFAULT NULL
);

-- 创建选项表
CREATE TABLE IF NOT EXISTS wa_options (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  value TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT '2022-08-15 00:00:00',
  updated_at DATETIME NOT NULL DEFAULT '2022-08-15 00:00:00'
);

-- 创建管理员角色表
CREATE TABLE IF NOT EXISTS wa_roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  rules TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  pid INTEGER DEFAULT NULL
);

-- 创建权限规则表
CREATE TABLE IF NOT EXISTS wa_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  icon TEXT DEFAULT NULL,
  key TEXT NOT NULL,
  pid INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  href TEXT DEFAULT NULL,
  type INTEGER NOT NULL DEFAULT 1,
  weight INTEGER DEFAULT 0
);

-- 创建附件表
CREATE TABLE IF NOT EXISTS wa_uploads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  admin_id INTEGER DEFAULT NULL,
  file_size INTEGER NOT NULL,
  mime_type TEXT NOT NULL,
  image_width INTEGER DEFAULT NULL,
  image_height INTEGER DEFAULT NULL,
  ext TEXT NOT NULL,
  storage TEXT NOT NULL DEFAULT 'local',
  category TEXT DEFAULT NULL,
  created_at DATE DEFAULT NULL,
  updated_at DATE DEFAULT NULL
);

-- 创建posts_ext表
CREATE TABLE IF NOT EXISTS post_ext (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id INTEGER NOT NULL,
  key TEXT NOT NULL,
  value TEXT NOT NULL,
  FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
);

-- 添加索引
CREATE INDEX idx_categories_parent_id ON categories (parent_id);
CREATE INDEX idx_categories_status ON categories (status);
CREATE INDEX idx_categories_deleted_at ON categories (deleted_at);

CREATE INDEX idx_posts_status ON posts (status);
CREATE INDEX idx_posts_featured ON posts (featured);
CREATE INDEX idx_posts_published_at ON posts (published_at);
CREATE INDEX idx_posts_deleted_at ON posts (deleted_at);

CREATE INDEX idx_post_category_post_id ON post_category (post_id);
CREATE INDEX idx_post_category_category_id ON post_category (category_id);

CREATE INDEX idx_post_author_post_id ON post_author (post_id);
CREATE INDEX idx_post_author_author_id ON post_author (author_id);

CREATE INDEX idx_links_status ON links (status);
CREATE INDEX idx_links_sort_order ON links (sort_order);
CREATE INDEX idx_links_deleted_at ON links (deleted_at);

CREATE INDEX idx_pages_deleted_at ON pages (deleted_at);

CREATE INDEX idx_settings_group ON settings ("group");

CREATE INDEX idx_media_author_id ON media (author_id);
CREATE INDEX idx_media_author_type ON media (author_type);
CREATE INDEX idx_media_filename ON media (filename);
CREATE INDEX idx_media_mime_type ON media (mime_type);
CREATE INDEX idx_media_deleted_at ON media (deleted_at);

CREATE INDEX idx_import_jobs_status ON import_jobs (status);
CREATE INDEX idx_import_jobs_author_id ON import_jobs (author_id);

CREATE INDEX idx_comments_post_id ON comments (post_id);
CREATE INDEX idx_comments_user_id ON comments (user_id);
CREATE INDEX idx_comments_parent_id ON comments (parent_id);
CREATE INDEX idx_comments_status ON comments (status);
CREATE INDEX idx_comments_deleted_at ON comments (deleted_at);

CREATE INDEX idx_tags_deleted_at ON tags (deleted_at);

CREATE INDEX idx_post_tag_post_id ON post_tag (post_id);
CREATE INDEX idx_post_tag_tag_id ON post_tag (tag_id);

CREATE INDEX idx_wa_uploads_category ON wa_uploads (category);
CREATE INDEX idx_wa_uploads_admin_id ON wa_uploads (admin_id);
CREATE INDEX idx_wa_uploads_name ON wa_uploads (name);
CREATE INDEX idx_wa_uploads_ext ON wa_uploads (ext);

CREATE INDEX idx_post_ext_id ON post_ext (id);
CREATE INDEX idx_post_ext_key ON post_ext (key);

-- 插入预定义表数据
INSERT INTO wa_options (name, value, created_at, updated_at) VALUES
('system_config', '{"logo":{"title":"Webman Admin","image":"/app/admin/admin/images/logo.png"},"menu":{"data":"/app/admin/rule/get","method":"GET","accordion":true,"collapse":false,"control":false,"controlWidth":500,"select":"0","async":true},"tab":{"enable":true,"keepState":true,"preload":false,"session":true,"max":"30","index":{"id":"0","href":"/app/admin/index/dashboard","title":"\u4eea\u8868\u76d8"}},"theme":{"defaultColor":"2","defaultMenu":"light-theme","defaultHeader":"light-theme","allowCustom":true,"banner":false},"colors":[{"id":"1","color":"#36b368","second":"#f0f9eb"},{"id":"2","color":"#2d8cf0","second":"#ecf5ff"},{"id":"3","color":"#f6ad55","second":"#fdf6ec"},{"id":"4","color":"#f56c6c","second":"#fef0f0"},{"id":"5","color":"#3963bc","second":"#ecf5ff"}],"other":{"keepLoad":"500","autoHead":false,"footer":false},"header":{"message":false}}', '2022-12-05 14:49:01', '2022-12-08 20:20:28'),
('table_form_schema_wa_users', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"sex":{"field":"sex","_field_id":"4","comment":"性别","control":"select","control_args":"url:/app/admin/dict/get/sex","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"avatar":{"field":"avatar","_field_id":"5","comment":"头像","control":"uploadImage","control_args":"url:/app/admin/upload/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"6","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"7","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"level":{"field":"level","_field_id":"8","comment":"等级","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"birthday":{"field":"birthday","_field_id":"9","comment":"生日","control":"datePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"money":{"field":"money","_field_id":"10","comment":"余额(元)","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"score":{"field":"score","_field_id":"11","comment":"积分","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"last_time":{"field":"last_time","_field_id":"12","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"last_ip":{"field":"last_ip","_field_id":"13","comment":"登录ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"join_time":{"field":"join_time","_field_id":"14","comment":"注册时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"join_ip":{"field":"join_ip","_field_id":"15","comment":"注册ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"token":{"field":"token","_field_id":"16","comment":"token","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"17","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"search_type":"between","list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"18","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"role":{"field":"role","_field_id":"19","comment":"角色","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"20","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-23 15:28:13'),
('table_form_schema_wa_roles', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"角色组","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"rules":{"field":"rules","_field_id":"2","comment":"权限","control":"treeSelectMulti","control_args":"url:/app/admin/rule/get?type=0,1,2","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"pid":{"field":"pid","_field_id":"5","comment":"父级","control":"select","control_args":"url:/app/admin/role/select?format=tree","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-19 14:24:25'),
('table_form_schema_wa_rules', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"title":{"field":"title","_field_id":"1","comment":"标题","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"icon":{"field":"icon","_field_id":"2","comment":"图标","control":"iconPicker","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"key":{"field":"key","_field_id":"3","comment":"标识","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"pid":{"field":"pid","_field_id":"4","comment":"上级菜单","control":"treeSelect","control_args":"/app/admin/rule/select?format=tree&type=0,1","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"5","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"6","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"href":{"field":"href","_field_id":"7","comment":"url","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"type":{"field":"type","_field_id":"8","comment":"类型","control":"select","control_args":"data:0:目录,1:菜单,2:权限","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"weight":{"field":"weight","_field_id":"9","comment":"排序","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:44:45'),
('table_form_schema_wa_admins', '{"id":{"field":"id","_field_id":"0","comment":"ID","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"between","form_show":false,"searchable":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"avatar":{"field":"avatar","_field_id":"4","comment":"头像","control":"uploadImage","control_args":"url:/app/admin/upload/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"5","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"6","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"created_at":{"field":"created_at","_field_id":"7","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"updated_at":{"field":"updated_at","_field_id":"8","comment":"更新时间","control":"dateTimePicker","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false},"login_at":{"field":"login_at","_field_id":"9","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"list_show":true,"search_type":"between","enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"10","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-23 15:36:48'),
('table_form_schema_wa_options', '{"id":{"field":"id","_field_id":"0","comment":"","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"键","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"value":{"field":"value","_field_id":"2","comment":"值","control":"textArea","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:36:57'),
('table_form_schema_wa_uploads', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"normal","form_show":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"名称","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"url":{"field":"url","_field_id":"2","comment":"文件","control":"upload","control_args":"url:/app/admin/upload/file","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"3","comment":"管理员","control":"select","control_args":"url:/app/admin/admin/select?format=select","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"file_size":{"field":"file_size","_field_id":"4","comment":"文件大小","control":"inputNumber","control_args":"","list_show":true,"search_type":"between","form_show":false,"enable_sort":false,"searchable":false},"mime_type":{"field":"mime_type","_field_id":"5","comment":"mime类型","control":"input","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_width":{"field":"image_width","_field_id":"6","comment":"图片宽度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_height":{"field":"image_height","_field_id":"7","comment":"图片高度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"ext":{"field":"ext","_field_id":"8","comment":"扩展名","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"storage":{"field":"storage","_field_id":"9","comment":"存储位置","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"10","comment":"上传时间","control":"dateTimePicker","control_args":"","searchable":true,"search_type":"between","form_show":false,"list_show":false,"enable_sort":false},"category":{"field":"category","_field_id":"11","comment":"类别","control":"select","control_args":"url:/app/admin/dict/get/upload","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"updated_at":{"field":"updated_at","_field_id":"12","comment":"更新时间","control":"dateTimePicker","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:47:45'),
('dict_upload', '[{"value":"1","name":"分类1"},{"value":"2","name":"分类2"},{"value":"3","name":"分类3"}]', '2022-12-04 16:24:13', '2022-12-04 16:24:13'),
('dict_sex', '[{"value":"0","name":"女"},{"value":"1","name":"男"}]', '2022-12-04 15:04:40', '2022-12-04 15:04:40'),
('dict_status', '[{"value":"0","name":"正常"},{"value":"1","name":"禁用"}]', '2022-12-04 15:05:09', '2022-12-04 15:05:09'),
('table_form_schema_wa_admin_roles', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"role_id":{"field":"role_id","_field_id":"1","comment":"角色id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"2","comment":"管理员id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-20 19:42:51'),
('dict_dict_name', '[{"value":"dict_name","name":"字典名称"},{"value":"status","name":"启禁用状态"},{"value":"sex","name":"性别"},{"value":"upload","name":"附件分类"}]', '2022-08-15 00:00:00', '2022-12-20 19:42:51');

INSERT INTO wa_roles (name, rules, created_at, updated_at) VALUES ('超级管理员', '*', '2022-08-13 16:15:01', '2022-12-23 12:05:07');

INSERT INTO links (name, url, description, icon, image, sort_order, status, target, redirect_type, show_url, content, email, callback_url, note, seo_title, seo_keywords, seo_description, custom_fields, created_at, updated_at, deleted_at) VALUES ('雨云',
        'https://www.rainyun.com/github_?s=blog-sys-ads',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'https://www.rainyun.com/favicon.ico',
        null,
        '1',
        1,
        '_blank',
        'direct',
        0,
        '# 超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'admin@biliwind.com',
        '',
        null,
        '雨云',
        '雨云,云服务器,服务器,性价比',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        null, '2025-9-26 11:00:00', '2022-12-23 12:05:07',
        null);

INSERT INTO settings (key, value, created_at, updated_at)
VALUES ('table_form_schema_wa_users', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "sex": {
           "field": "sex",
           "_field_id": "4",
           "comment": "性别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/sex",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "5",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "6",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "7",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "level": {
           "field": "level",
           "_field_id": "8",
           "comment": "等级",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "birthday": {
           "field": "birthday",
           "_field_id": "9",
           "comment": "生日",
           "control": "datePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "money": {
           "field": "money",
           "_field_id": "10",
           "comment": "余额(元)",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "score": {
           "field": "score",
           "_field_id": "11",
           "comment": "积分",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "last_time": {
           "field": "last_time",
           "_field_id": "12",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "last_ip": {
           "field": "last_ip",
           "_field_id": "13",
           "comment": "登录ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "join_time": {
           "field": "join_time",
           "_field_id": "14",
           "comment": "注册时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "join_ip": {
           "field": "join_ip",
           "_field_id": "15",
           "comment": "注册ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "token": {
           "field": "token",
           "_field_id": "16",
           "comment": "token",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "17",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "18",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "role": {
           "field": "role",
           "_field_id": "19",
           "comment": "角色",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "20",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-23 15:28:13'),
       ('table_form_schema_wa_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "角色组",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "rules": {
           "field": "rules",
           "_field_id": "2",
           "comment": "权限",
           "control": "treeSelectMulti",
           "control_args": "url:/app/admin/rule/get?type=0,1,2",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "5",
           "comment": "父级",
           "control": "select",
           "control_args": "url:/app/admin/role/select?format=tree",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-19 14:24:25'),
       ('table_form_schema_wa_rules', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "title": {
           "field": "title",
           "_field_id": "1",
           "comment": "标题",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "icon": {
           "field": "icon",
           "_field_id": "2",
           "comment": "图标",
           "control": "iconPicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "key": {
           "field": "key",
           "_field_id": "3",
           "comment": "标识",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "4",
           "comment": "上级菜单",
           "control": "treeSelect",
           "control_args": "/app/admin/rule/select?format=tree&type=0,1",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "5",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "6",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "href": {
           "field": "href",
           "_field_id": "7",
           "comment": "url",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "type": {
           "field": "type",
           "_field_id": "8",
           "comment": "类型",
           "control": "select",
           "control_args": "data:0:目录,1:菜单,2:权限",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "weight": {
           "field": "weight",
           "_field_id": "9",
           "comment": "排序",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:44:45'),
       ('table_form_schema_wa_admins', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "ID",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "between",
           "form_show": false,
           "searchable": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "4",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "5",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "6",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "7",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "8",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "login_at": {
           "field": "login_at",
           "_field_id": "9",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "between",
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "10",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-23 15:36:48'),
       ('table_form_schema_wa_options', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "键",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "value": {
           "field": "value",
           "_field_id": "2",
           "comment": "值",
           "control": "textArea",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:36:57'),
       ('table_form_schema_wa_uploads', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "normal",
           "form_show": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "名称",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "url": {
           "field": "url",
           "_field_id": "2",
           "comment": "文件",
           "control": "upload",
           "control_args": "url:/app/admin/upload/file",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "3",
           "comment": "管理员",
           "control": "select",
           "control_args": "url:/app/admin/admin/select?format=select",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "file_size": {
           "field": "file_size",
           "_field_id": "4",
           "comment": "文件大小",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "between",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "mime_type": {
           "field": "mime_type",
           "_field_id": "5",
           "comment": "mime类型",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_width": {
           "field": "image_width",
           "_field_id": "6",
           "comment": "图片宽度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_height": {
           "field": "image_height",
           "_field_id": "7",
           "comment": "图片高度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "ext": {
           "field": "ext",
           "_field_id": "8",
           "comment": "扩展名",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "storage": {
           "field": "storage",
           "_field_id": "9",
           "comment": "存储位置",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "10",
           "comment": "上传时间",
           "control": "dateTimePicker",
           "control_args": "",
           "searchable": true,
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false
         },
         "category": {
           "field": "category",
           "_field_id": "11",
           "comment": "类别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/upload",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "12",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:47:45'),
       ('dict_upload', '[
         {
           "value": "1",
           "name": "分类1"
         },
         {
           "value": "2",
           "name": "分类2"
         },
         {
           "value": "3",
           "name": "分类3"
         }
       ]', '2022-12-04 16:24:13', '2022-12-04 16:24:13'),
       ('dict_sex', '[
         {
           "value": "0",
           "name": "女"
         },
         {
           "value": "1",
           "name": "男"
         }
       ]', '2022-12-04 15:04:40', '2022-12-04 15:04:40'),
       ('dict_status', '[
         {
           "value": "0",
           "name": "正常"
         },
         {
           "value": "1",
           "name": "禁用"
         }
       ]', '2022-12-04 15:05:09', '2022-12-04 15:05:09'),
       ('table_form_schema_wa_admin_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "role_id": {
           "field": "role_id",
           "_field_id": "1",
           "comment": "角色id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "2",
           "comment": "管理员id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-20 19:42:51'),
       ('dict_dict_name', '[
         {
           "value": "dict_name",
           "name": "字典名称"
         },
         {
           "value": "status",
           "name": "启禁用状态"
         },
         {
           "value": "sex",
           "name": "性别"
         },
         {
           "value": "upload",
           "name": "附件分类"
         }
       ]', '2022-08-15 00:00:00', '2022-12-20 19:42:51');