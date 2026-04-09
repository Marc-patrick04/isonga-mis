-- Create hero table for managing homepage Quick Links images
CREATE TABLE hero (
    id SERIAL PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    image_url TEXT,
    icon VARCHAR(50) DEFAULT 'fa-link',
    link_url VARCHAR(255) DEFAULT '#',
    description TEXT,
    display_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default hero items for Quick Links section
INSERT INTO hero (title, slug, image_url, icon, link_url, description, display_order, is_active) VALUES
('Announcements', 'announcements', NULL, 'fa-bullhorn', 'announcements.php', 'Official communications from RPSU leadership and college administration', 1, TRUE),
('Campus News', 'news', NULL, 'fa-newspaper', 'news.php', 'Latest happenings, achievements, and stories from around campus', 2, TRUE),
('Events', 'events', NULL, 'fa-calendar-alt', 'events.php', 'Upcoming academic, cultural, and social events calendar', 3, TRUE),
('Committee', 'committee', NULL, 'fa-users', 'committee.php', 'Meet your dedicated student representatives and leaders', 4, TRUE);

-- Create function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create trigger to auto-update updated_at
CREATE TRIGGER update_hero_updated_at BEFORE UPDATE ON hero
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Grant permissions (adjust as needed)
-- GRANT ALL PRIVILEGES ON hero TO your_user;
-- GRANT USAGE, SELECT ON SEQUENCE hero_id_seq TO your_user;