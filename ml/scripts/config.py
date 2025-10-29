# ml/scripts/config.py
DB_CONFIG = {
    'host': '127.0.0.1',
    'user': 'root',
    'password': '',  # Your MySQL password (leave empty if none)
    'database': 'barangay_kapasigan',
    'port': 3306
}

# Model paths (use Windows paths)
MODEL_PATH = '../models/'
DATA_PATH = '../data/'

# Feature engineering settings
APPROVAL_THRESHOLD = 0.7  # 70% confidence for auto-approve
NOSHOW_THRESHOLD = 0.6    # 60% probability triggers extra reminder