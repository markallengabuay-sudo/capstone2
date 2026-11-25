CREATE TABLE IF NOT EXISTS case_archive_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    action_type ENUM('archive', 'revive') NOT NULL,
    reason TEXT NOT NULL,
    court_order_number VARCHAR(100) NOT NULL,
    court_order_date DATE NOT NULL,
    judge_name VARCHAR(100) NOT NULL,
    order_document_id INT NOT NULL,
    prior_status VARCHAR(100) DEFAULT NULL,
    recorded_by INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_case_archive_events_case (complaint_id, id),
    INDEX idx_case_archive_events_order_document (order_document_id),
    CONSTRAINT fk_case_archive_events_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE RESTRICT,
    CONSTRAINT fk_case_archive_events_order_document
        FOREIGN KEY (order_document_id) REFERENCES case_documents(id) ON DELETE RESTRICT,
    CONSTRAINT fk_case_archive_events_recorder
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
