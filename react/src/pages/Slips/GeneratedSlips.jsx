import React, { useState, useEffect, useCallback, useMemo } from "react";
import { useParams, useNavigate } from "react-router-dom";
import {
  Container,
  Typography,
  Box,
  Paper,
  Grid,
  Chip,
  Button,
  Stack,
  CircularProgress,
  Alert,
  ToggleButton,
  ToggleButtonGroup,
  TextField,
  InputAdornment,
  MenuItem,
  Select,
  FormControl,
  InputLabel,
  IconButton,
  Tooltip,
  Card,
  CardContent,
  LinearProgress,
  Divider,
  styled,
  alpha,
  useTheme,
  Fade,
  Zoom,
} from "@mui/material";
import {
  ArrowBack,
  Refresh,
  TrendingUp,
  FilterList,
  Search,
  Download,
  Sort,
  BarChart,
  ViewList,
  AttachMoney,
  Warning,
  Sports,
  Whatshot,
  EmojiEvents,
  Paid,
  Security,
  Insights,
} from "@mui/icons-material";

import ConfidenceChart from "../../components/matches/ConfidenceChart";
import SlipCard from "../../components/matches/SlipCard";
import SlipDetailModal from "../../components/matches/SlipDetailModal";
import slipApi from "../../services/api/slipApi";

// Styled Components for Dark Theme
const ScrollableSlipsContainer = styled(Box)(({ theme }) => ({
  height: "calc(100vh - 420px)",
  overflowY: "auto",
  paddingRight: theme.spacing(1.5),

  "&::-webkit-scrollbar": {
    width: "10px",
  },
  "&::-webkit-scrollbar-track": {
    background: alpha(theme.palette.grey[900], 0.3),
    borderRadius: "10px",
  },
  "&::-webkit-scrollbar-thumb": {
    background: `linear-gradient(180deg, ${theme.palette.primary.dark} 0%, ${theme.palette.primary.main} 100%)`,
    borderRadius: "10px",
    border: `2px solid ${alpha(theme.palette.background.default, 0.3)}`,
    "&:hover": {
      background: `linear-gradient(180deg, ${theme.palette.primary.main} 0%, ${theme.palette.primary.light} 100%)`,
    },
  },
}));

const StickySidebar = styled(Paper)(({ theme }) => ({
  position: "sticky",
  top: theme.spacing(3),
  height: "calc(100vh - 420px)",
  overflowY: "auto",
  background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.8)} 0%, ${alpha(
    theme.palette.background.default,
    0.95
  )} 100%)`,
  backdropFilter: "blur(10px)",
  border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
  boxShadow: `0 8px 32px ${alpha(theme.palette.common.black, 0.3)}`,

  "&::-webkit-scrollbar": {
    width: "8px",
  },
  "&::-webkit-scrollbar-track": {
    background: alpha(theme.palette.grey[900], 0.2),
    borderRadius: "4px",
  },
  "&::-webkit-scrollbar-thumb": {
    background: alpha(theme.palette.secondary.main, 0.5),
    borderRadius: "4px",
    "&:hover": {
      background: alpha(theme.palette.secondary.main, 0.7),
    },
  },
}));

const FilterSection = styled(Paper)(({ theme }) => ({
  position: "sticky",
  top: 0,
  zIndex: 1100,
  background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.95)} 0%, ${alpha(
    theme.palette.background.default,
    0.98
  )} 100%)`,
  backdropFilter: "blur(10px)",
  border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
  boxShadow: `0 4px 20px ${alpha(theme.palette.common.black, 0.25)}`,
}));

const StatsCard = styled(Card)(({ theme }) => ({
  background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.9)} 0%, ${alpha(
    theme.palette.background.default,
    0.95
  )} 100%)`,
  backdropFilter: "blur(10px)",
  border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
  transition: "all 0.3s ease",
  "&:hover": {
    transform: "translateY(-4px)",
    boxShadow: `0 12px 32px ${alpha(theme.palette.primary.main, 0.15)}`,
    borderColor: alpha(theme.palette.primary.main, 0.3),
  },
}));

const GradientButton = styled(Button)(({ theme }) => ({
  background: `linear-gradient(135deg, ${theme.palette.primary.main} 0%, ${theme.palette.secondary.main} 100%)`,
  color: theme.palette.common.white,
  fontWeight: 600,
  textTransform: "none",
  transition: "all 0.3s ease",
  "&:hover": {
    transform: "translateY(-2px)",
    boxShadow: `0 8px 25px ${alpha(theme.palette.primary.main, 0.3)}`,
  },
}));

const OutlinedButton = styled(Button)(({ theme }) => ({
  border: `1px solid ${alpha(theme.palette.divider, 0.3)}`,
  background: alpha(theme.palette.background.paper, 0.6),
  transition: "all 0.3s ease",
  "&:hover": {
    background: alpha(theme.palette.background.paper, 0.9),
    borderColor: theme.palette.primary.main,
    transform: "translateY(-2px)",
  },
}));

const RiskBadge = styled(Chip)(({ theme, risklevel }) => {
  const colors = {
    high: {
      bg: alpha(theme.palette.error.main, 0.15),
      text: theme.palette.error.light,
      border: alpha(theme.palette.error.main, 0.3),
    },
    medium: {
      bg: alpha(theme.palette.warning.main, 0.15),
      text: theme.palette.warning.light,
      border: alpha(theme.palette.warning.main, 0.3),
    },
    low: {
      bg: alpha(theme.palette.success.main, 0.15),
      text: theme.palette.success.light,
      border: alpha(theme.palette.success.main, 0.3),
    },
  };

  const color = colors[risklevel?.toLowerCase()] || colors.medium;

  return {
    background: color.bg,
    color: color.text,
    border: `1px solid ${color.border}`,
    fontWeight: 600,
    backdropFilter: "blur(5px)",
  };
});

const GeneratedSlips = () => {
  const { masterSlipId } = useParams();
  const navigate = useNavigate();
  const theme = useTheme();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [slips, setSlips] = useState([]);
  const [statistics, setStatistics] = useState(null);
  const [selectedSlip, setSelectedSlip] = useState(null);
  const [detailModalOpen, setDetailModalOpen] = useState(false);

  // State for filters and sorting
  const [viewMode, setViewMode] = useState("cards");
  const [sortBy, setSortBy] = useState("confidence");
  const [filterRisk, setFilterRisk] = useState("all");
  const [searchTerm, setSearchTerm] = useState("");
  const [minConfidence, setMinConfidence] = useState(0);
  const [masterSlipInfo, setMasterSlipInfo] = useState({
    stake: 0,
    total_slips: 0,
  });

  // Fetch slips data
  const fetchSlips = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await slipApi.getGeneratedSlips(masterSlipId);
      const fetchedData = response.data;

      setMasterSlipInfo({
        stake: fetchedData.master_slip?.stake || 0,
        total_slips:
          fetchedData.master_slip?.total_generated_slips ||
          fetchedData.generated_slips?.length ||
          0,
      });

      setSlips(fetchedData.generated_slips || []);

      const stats = calculateStatistics(fetchedData.generated_slips || []);
      setStatistics(stats);
    } catch (err) {
      setError("Failed to load generated slips. Please try again.");
      console.error("Error fetching slips:", err);
    } finally {
      setLoading(false);
    }
  }, [masterSlipId]);

  // Calculate statistics
  const calculateStatistics = (slipsData) => {
    if (!slipsData.length || !masterSlipInfo.total_slips) return null;

    const totalSlips = slipsData.length;
    const stakePerSlip = masterSlipInfo.stake / totalSlips;

    const slipsWithCalculations = slipsData.map((slip) => ({
      ...slip,
      calculated_stake: stakePerSlip,
      calculated_return: stakePerSlip * slip.total_odds,
    }));

    const avgConfidence =
      slipsData.reduce((sum, slip) => sum + (slip.confidence_score || 0), 0) /
      totalSlips;

    const avgOdds =
      slipsData.reduce((sum, slip) => sum + slip.total_odds, 0) / totalSlips;

    const avgReturn =
      slipsWithCalculations.reduce(
        (sum, slip) => sum + slip.calculated_return,
        0
      ) / totalSlips;

    const riskDistribution = slipsData.reduce((acc, slip) => {
      const riskLevel = slip.risk_level || "Medium";
      acc[riskLevel] = (acc[riskLevel] || 0) + 1;
      return acc;
    }, {});

    return {
      totalSlips,
      avgConfidence: avgConfidence.toFixed(1),
      avgOdds: avgOdds.toFixed(2),
      avgReturn: avgReturn.toFixed(2),
      riskDistribution,
      highestConfidence: Math.max(
        ...slipsData.map((s) => s.confidence_score || 0)
      ).toFixed(1),
      highestReturn: Math.max(
        ...slipsWithCalculations.map((s) => s.calculated_return || 0)
      ).toFixed(2),
      stakePerSlip: stakePerSlip.toFixed(2),
    };
  };

  // Filter and sort slips
  const filteredSlips = useMemo(() => {
    let filtered = [...slips];

    const stakePerSlip =
      masterSlipInfo.total_slips > 0
        ? masterSlipInfo.stake / masterSlipInfo.total_slips
        : 0;

    let filteredWithCalculations = filtered.map((slip) => ({
      ...slip,
      calculated_stake: stakePerSlip,
      calculated_return: stakePerSlip * slip.total_odds,
    }));

    // Apply search filter
    if (searchTerm) {
      filteredWithCalculations = filteredWithCalculations.filter(
        (slip) =>
          slip.slip_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
          slip.legs.some(
            (leg) =>
              leg.match_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
              leg.selection.toLowerCase().includes(searchTerm.toLowerCase())
          )
      );
    }

    // Apply risk filter
    if (filterRisk !== "all") {
      filteredWithCalculations = filteredWithCalculations.filter((slip) =>
        slip.risk_level.toLowerCase().includes(filterRisk.toLowerCase())
      );
    }

    // Apply confidence filter
    filteredWithCalculations = filteredWithCalculations.filter(
      (slip) => slip.confidence_score >= minConfidence
    );

    // Apply sorting
    filteredWithCalculations.sort((a, b) => {
      switch (sortBy) {
        case "confidence":
          return b.confidence_score - a.confidence_score;
        case "odds":
          return b.total_odds - a.total_odds;
        case "return":
          return b.calculated_return - a.calculated_return;
        case "risk":
          const riskOrder = { High: 3, Medium: 2, Low: 1 };
          return riskOrder[b.risk_level] - riskOrder[a.risk_level];
        default:
          return 0;
      }
    });

    return filteredWithCalculations;
  }, [slips, masterSlipInfo, searchTerm, filterRisk, minConfidence, sortBy]);

  // Handle slip deletion
  const handleDeleteSlip = async (slipId) => {
    try {
      const newSlips = slips.filter((slip) => slip.slip_id !== slipId);
      setSlips(newSlips);
      const stats = calculateStatistics(newSlips);
      setStatistics(stats);
    } catch (err) {
      setError("Failed to delete slip");
      console.error("Error deleting slip:", err);
    }
  };

  // Handle slip detail view
  const handleViewDetail = (slipId) => {
    const slip = slips.find((s) => s.slip_id === slipId);
    setSelectedSlip(slip);
    setDetailModalOpen(true);
  };

  // Export slips to CSV
  const handleExportCSV = async () => {
    try {
      alert("Export feature would download CSV file in production");
    } catch (err) {
      setError("Failed to export slips");
      console.error("Error exporting slips:", err);
    }
  };

  // Initial fetch
  useEffect(() => {
    fetchSlips();
  }, [fetchSlips]);

  if (loading) {
    return (
      <Container maxWidth="xl" sx={{ py: 4 }}>
        <Box
          display="flex"
          justifyContent="center"
          alignItems="center"
          minHeight="70vh"
          flexDirection="column"
          gap={3}
        >
          <CircularProgress
            size={80}
            thickness={4}
            sx={{
              color: theme.palette.primary.main,
              filter: `drop-shadow(0 0 10px ${alpha(theme.palette.primary.main, 0.5)})`,
            }}
          />
          <Typography
            variant="h6"
            color="text.secondary"
            sx={{
              animation: "pulse 2s infinite",
              "@keyframes pulse": {
                "0%, 100%": { opacity: 0.7 },
                "50%": { opacity: 1 },
              },
            }}
          >
            Loading your betting slips...
          </Typography>
        </Box>
      </Container>
    );
  }

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      {/* Header with Gradient */}
      <Fade in={!loading} timeout={800}>
        <Box mb={4}>
          <Box
            display="flex"
            justifyContent="space-between"
            alignItems="center"
            mb={4}
            sx={{
              background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.8)} 0%, ${alpha(
                theme.palette.background.default,
                0.95
              )} 100%)`,
              padding: 3,
              borderRadius: 4,
              border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
              backdropFilter: "blur(10px)",
            }}
          >
            <Box>
              <Typography
                variant="h3"
                component="h1"
                gutterBottom
                fontWeight={800}
                sx={{
                  background: `linear-gradient(135deg, ${theme.palette.primary.main} 0%, ${theme.palette.secondary.main} 100%)`,
                  WebkitBackgroundClip: "text",
                  WebkitTextFillColor: "transparent",
                  backgroundClip: "text",
                }}
              >
                Generated Slips
              </Typography>
              <Box display="flex" alignItems="center" gap={2} flexWrap="wrap">
                <Typography variant="subtitle1" color="text.secondary">
                  Master Slip: <strong>#{masterSlipId}</strong>
                </Typography>
                <Chip
                  icon={<Whatshot />}
                  label={`${slips.length} slips`}
                  size="small"
                  sx={{
                    background: alpha(theme.palette.primary.main, 0.1),
                    color: theme.palette.primary.light,
                    fontWeight: 600,
                  }}
                />
                <Chip
                  icon={<Paid />}
                  label={`€${masterSlipInfo.stake.toFixed(2)} total stake`}
                  size="small"
                  sx={{
                    background: alpha(theme.palette.success.main, 0.1),
                    color: theme.palette.success.light,
                    fontWeight: 600,
                  }}
                />
              </Box>
            </Box>
            <Stack direction="row" spacing={2}>
              <OutlinedButton
                startIcon={<ArrowBack />}
                onClick={() => navigate(-1)}
                sx={{ borderRadius: 3 }}
              >
                Back
              </OutlinedButton>
              <OutlinedButton
                startIcon={<Refresh />}
                onClick={fetchSlips}
                sx={{ borderRadius: 3 }}
              >
                Refresh
              </OutlinedButton>
              <GradientButton
                startIcon={<Download />}
                onClick={handleExportCSV}
                sx={{ borderRadius: 3 }}
              >
                Export CSV
              </GradientButton>
            </Stack>
          </Box>

          {error && (
            <Alert
              severity="error"
              sx={{
                mb: 3,
                borderRadius: 3,
                background: alpha(theme.palette.error.main, 0.1),
                border: `1px solid ${alpha(theme.palette.error.main, 0.2)}`,
                color: theme.palette.error.light,
              }}
            >
              {error}
            </Alert>
          )}

          {/* Statistics Cards - Grid with Hover Effects */}
          {statistics && (
            <Zoom in={!loading} timeout={1000}>
              <Grid container spacing={3} mb={4}>
                {[
                  {
                    label: "Total Slips",
                    value: statistics.totalSlips,
                    icon: <ViewList />,
                    color: "primary",
                    gradient: `linear-gradient(135deg, ${theme.palette.primary.main} 0%, ${theme.palette.primary.dark} 100%)`,
                  },
                  {
                    label: "Avg Confidence",
                    value: `${statistics.avgConfidence}%`,
                    icon: <TrendingUp />,
                    color: "success",
                    gradient: `linear-gradient(135deg, ${theme.palette.success.main} 0%, ${theme.palette.success.dark} 100%)`,
                  },
                  {
                    label: "Avg Return",
                    value: `€${statistics.avgReturn}`,
                    icon: <AttachMoney />,
                    color: "warning",
                    gradient: `linear-gradient(135deg, ${theme.palette.warning.main} 0%, ${theme.palette.warning.dark} 100%)`,
                  },
                  {
                    label: "Highest Confidence",
                    value: `${statistics.highestConfidence}%`,
                    icon: <EmojiEvents />,
                    color: "info",
                    gradient: `linear-gradient(135deg, ${theme.palette.info.main} 0%, ${theme.palette.info.dark} 100%)`,
                  },
                  {
                    label: "Stake per Slip",
                    value: `€${statistics.stakePerSlip}`,
                    icon: <Security />,
                    color: "secondary",
                    gradient: `linear-gradient(135deg, ${theme.palette.secondary.main} 0%, ${theme.palette.secondary.dark} 100%)`,
                  },
                ].map((stat, index) => (
                  <Grid item xs={12} sm={6} md={2.4} key={index}>
                    <StatsCard>
                      <CardContent>
                        <Box
                          display="flex"
                          alignItems="center"
                          gap={2}
                          mb={1.5}
                        >
                          <Box
                            sx={{
                              background: stat.gradient,
                              borderRadius: 2,
                              p: 1.5,
                              display: "flex",
                              alignItems: "center",
                              justifyContent: "center",
                            }}
                          >
                            {React.cloneElement(stat.icon, {
                              sx: { fontSize: 20, color: "white" },
                            })}
                          </Box>
                          <Typography
                            variant="subtitle2"
                            color="text.secondary"
                            sx={{ opacity: 0.8 }}
                          >
                            {stat.label}
                          </Typography>
                        </Box>
                        <Typography
                          variant="h4"
                          sx={{
                            fontWeight: 800,
                            background: stat.gradient,
                            WebkitBackgroundClip: "text",
                            WebkitTextFillColor: "transparent",
                            backgroundClip: "text",
                          }}
                        >
                          {stat.value}
                        </Typography>
                      </CardContent>
                    </StatsCard>
                  </Grid>
                ))}
              </Grid>
            </Zoom>
          )}
        </Box>
      </Fade>

      {/* Filters and Controls - Glassmorphism */}
      <FilterSection sx={{ p: 3, mb: 3, borderRadius: 4 }}>
        <Grid container spacing={2} alignItems="center">
          <Grid item xs={12} md={3}>
            <TextField
              fullWidth
              size="small"
              placeholder="Search slips, matches, or selections..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              InputProps={{
                startAdornment: (
                  <InputAdornment position="start">
                    <Search
                      sx={{ color: alpha(theme.palette.common.white, 0.6) }}
                    />
                  </InputAdornment>
                ),
                sx: {
                  borderRadius: 3,
                  background: alpha(theme.palette.background.paper, 0.6),
                  "&:hover": {
                    background: alpha(theme.palette.background.paper, 0.8),
                  },
                  "& .MuiOutlinedInput-notchedOutline": {
                    borderColor: alpha(theme.palette.divider, 0.2),
                  },
                },
              }}
            />
          </Grid>
          <Grid item xs={12} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel
                sx={{ color: alpha(theme.palette.common.white, 0.7) }}
              >
                Sort By
              </InputLabel>
              <Select
                value={sortBy}
                label="Sort By"
                onChange={(e) => setSortBy(e.target.value)}
                sx={{
                  borderRadius: 3,
                  background: alpha(theme.palette.background.paper, 0.6),
                  "&:hover": {
                    background: alpha(theme.palette.background.paper, 0.8),
                  },
                }}
              >
                <MenuItem value="confidence">Confidence</MenuItem>
                <MenuItem value="odds">Total Odds</MenuItem>
                <MenuItem value="return">Potential Return</MenuItem>
                <MenuItem value="risk">Risk Level</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={12} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel
                sx={{ color: alpha(theme.palette.common.white, 0.7) }}
              >
                Risk Level
              </InputLabel>
              <Select
                value={filterRisk}
                label="Risk Level"
                onChange={(e) => setFilterRisk(e.target.value)}
                sx={{
                  borderRadius: 3,
                  background: alpha(theme.palette.background.paper, 0.6),
                  "&:hover": {
                    background: alpha(theme.palette.background.paper, 0.8),
                  },
                }}
              >
                <MenuItem value="all">All Risks</MenuItem>
                <MenuItem value="low">Low Risk</MenuItem>
                <MenuItem value="medium">Medium Risk</MenuItem>
                <MenuItem value="high">High Risk</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={12} md={2}>
            <TextField
              fullWidth
              size="small"
              type="number"
              label="Min Confidence"
              value={minConfidence}
              onChange={(e) => setMinConfidence(Number(e.target.value))}
              InputProps={{
                endAdornment: <InputAdornment position="end">%</InputAdornment>,
                sx: {
                  borderRadius: 3,
                  background: alpha(theme.palette.background.paper, 0.6),
                  "&:hover": {
                    background: alpha(theme.palette.background.paper, 0.8),
                  },
                },
              }}
            />
          </Grid>
          <Grid item xs={12} md={3}>
            <ToggleButtonGroup
              exclusive
              value={viewMode}
              onChange={(_, value) => value && setViewMode(value)}
              size="small"
              fullWidth
              sx={{
                "& .MuiToggleButton-root": {
                  borderRadius: 2,
                  background: alpha(theme.palette.background.paper, 0.6),
                  borderColor: alpha(theme.palette.divider, 0.2),
                  color: alpha(theme.palette.common.white, 0.7),
                  "&.Mui-selected": {
                    background: `linear-gradient(135deg, ${alpha(theme.palette.primary.main, 0.2)} 0%, ${alpha(
                      theme.palette.primary.main,
                      0.4
                    )} 100%)`,
                    color: theme.palette.primary.light,
                    borderColor: alpha(theme.palette.primary.main, 0.3),
                  },
                },
              }}
            >
              <ToggleButton value="cards">
                <ViewList sx={{ mr: 1 }} />
                Cards View
              </ToggleButton>
              <ToggleButton value="chart">
                <BarChart sx={{ mr: 1 }} />
                Analytics
              </ToggleButton>
            </ToggleButtonGroup>
          </Grid>
        </Grid>
      </FilterSection>

      {/* Results Summary */}
      <Box
        display="flex"
        justifyContent="space-between"
        alignItems="center"
        mb={3}
      >
        <Box display="flex" alignItems="center" gap={2}>
          <Typography variant="h6" fontWeight={600}>
            Showing {filteredSlips.length} of {slips.length} slips
          </Typography>
          {filteredSlips.length > 0 && (
            <Typography
              variant="body2"
              color="text.secondary"
              sx={{ opacity: 0.7 }}
            >
              Sorted by {sortBy === "return" ? "potential return" : sortBy}
            </Typography>
          )}
        </Box>
        {filterRisk !== "all" && (
          <RiskBadge
            label={`${filterRisk.toUpperCase()} RISK`}
            onDelete={() => setFilterRisk("all")}
            risklevel={filterRisk}
            icon={<Warning />}
          />
        )}
      </Box>

      {/* Content Area */}
      {viewMode === "chart" ? (
        <Paper
          sx={{
            p: 4,
            mb: 3,
            height: 500,
            borderRadius: 4,
            background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.8)} 0%, ${alpha(
              theme.palette.background.default,
              0.95
            )} 100%)`,
            border: `1px solid ${alpha(theme.palette.divider, 0.1)}`,
          }}
        >
          <ConfidenceChart slips={filteredSlips} />
        </Paper>
      ) : (
        <Grid container spacing={3}>
          {/* Main Slips List */}
          <Grid item xs={12} lg={8}>
            {filteredSlips.length === 0 ? (
              <Paper
                sx={{
                  p: 6,
                  textAlign: "center",
                  borderRadius: 4,
                  background: `linear-gradient(135deg, ${alpha(theme.palette.background.paper, 0.8)} 0%, ${alpha(
                    theme.palette.background.default,
                    0.95
                  )} 100%)`,
                  border: `1px dashed ${alpha(theme.palette.divider, 0.3)}`,
                }}
              >
                <Insights sx={{ fontSize: 60, mb: 2, opacity: 0.3 }} />
                <Typography variant="h5" color="text.secondary" gutterBottom>
                  No slips found
                </Typography>
                <Typography
                  variant="body2"
                  color="text.secondary"
                  sx={{ opacity: 0.7 }}
                >
                  Try adjusting your filters or search terms
                </Typography>
              </Paper>
            ) : (
              <ScrollableSlipsContainer>
                <Stack spacing={2.5}>
                  {filteredSlips.map((slip, index) => (
                    <SlipCard
                      key={slip.slip_id}
                      slip={slip}
                      onDelete={handleDeleteSlip}
                      onViewDetail={handleViewDetail}
                      index={index}
                    />
                  ))}
                </Stack>
              </ScrollableSlipsContainer>
            )}
          </Grid>

          {/* Sidebar with Analytics */}
          <Grid item xs={12} lg={4}>
            <StickySidebar sx={{ p: 3, borderRadius: 4 }}>
              <Typography
                variant="h6"
                gutterBottom
                fontWeight={700}
                sx={{ mb: 3 }}
              >
                <Box component="span" sx={{ opacity: 0.8 }}>
                  📊
                </Box>{" "}
                Risk Analytics
              </Typography>

              {statistics?.riskDistribution && (
                <Stack spacing={3} mb={4}>
                  {Object.entries(statistics.riskDistribution).map(
                    ([risk, count]) => {
                      const percentageNum =
                        (count / statistics.totalSlips) * 100;
                      const colors = {
                        High: theme.palette.error.main,
                        Medium: theme.palette.warning.main,
                        Low: theme.palette.success.main,
                      };

                      return (
                        <Box key={risk}>
                          <Box
                            display="flex"
                            justifyContent="space-between"
                            alignItems="center"
                            mb={1}
                          >
                            <Box display="flex" alignItems="center" gap={1}>
                              <Box
                                sx={{
                                  width: 12,
                                  height: 12,
                                  borderRadius: "50%",
                                  background:
                                    colors[risk] || theme.palette.grey[500],
                                  boxShadow: `0 0 10px ${alpha(colors[risk] || theme.palette.grey[500], 0.3)}`,
                                }}
                              />
                              <Typography variant="body2" fontWeight={600}>
                                {risk} Risk
                              </Typography>
                            </Box>
                            <Typography variant="body2" fontWeight={700}>
                              {count} ({percentageNum.toFixed(1)}%)
                            </Typography>
                          </Box>
                          <LinearProgress
                            variant="determinate"
                            value={percentageNum}
                            sx={{
                              height: 10,
                              borderRadius: 5,
                              background: alpha(theme.palette.grey[800], 0.3),
                              "& .MuiLinearProgress-bar": {
                                background: `linear-gradient(90deg, ${alpha(colors[risk], 0.5)} 0%, ${colors[risk]} 100%)`,
                                borderRadius: 5,
                                boxShadow: `0 0 8px ${alpha(colors[risk], 0.3)}`,
                              },
                            }}
                          />
                        </Box>
                      );
                    }
                  )}
                </Stack>
              )}

              <Divider
                sx={{ my: 3, borderColor: alpha(theme.palette.divider, 0.1) }}
              />

              <Typography
                variant="h6"
                gutterBottom
                fontWeight={700}
                sx={{ mb: 3 }}
              >
                <Box component="span" sx={{ opacity: 0.8 }}>
                  ⚡
                </Box>{" "}
                Quick Actions
              </Typography>
              <Stack spacing={2} mb={3}>
                <OutlinedButton
                  fullWidth
                  startIcon={<TrendingUp />}
                  onClick={() => setSortBy("confidence")}
                  sx={{
                    borderRadius: 3,
                    justifyContent: "flex-start",
                    py: 1.5,
                  }}
                >
                  Sort by Highest Confidence
                </OutlinedButton>
                <OutlinedButton
                  fullWidth
                  startIcon={<AttachMoney />}
                  onClick={() => setSortBy("return")}
                  sx={{
                    borderRadius: 3,
                    justifyContent: "flex-start",
                    py: 1.5,
                  }}
                >
                  Sort by Highest Return
                </OutlinedButton>
                <OutlinedButton
                  fullWidth
                  startIcon={<Security />}
                  onClick={() => setFilterRisk("low")}
                  sx={{
                    borderRadius: 3,
                    justifyContent: "flex-start",
                    py: 1.5,
                  }}
                >
                  Show Low Risk Only
                </OutlinedButton>
              </Stack>

              {/* Performance Summary */}
              <Divider
                sx={{ my: 3, borderColor: alpha(theme.palette.divider, 0.1) }}
              />
              <Typography
                variant="h6"
                gutterBottom
                fontWeight={700}
                sx={{ mb: 3 }}
              >
                <Box component="span" sx={{ opacity: 0.8 }}>
                  📈
                </Box>{" "}
                Performance Summary
              </Typography>
              <Stack spacing={2}>
                <Box
                  display="flex"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Typography variant="body2" color="text.secondary">
                    Filtered Slips:
                  </Typography>
                  <Typography variant="body1" fontWeight={700}>
                    {filteredSlips.length}
                  </Typography>
                </Box>
                <Box
                  display="flex"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Typography variant="body2" color="text.secondary">
                    Avg Confidence:
                  </Typography>
                  <Typography
                    variant="body1"
                    fontWeight={700}
                    sx={{
                      color: theme.palette.success.light,
                    }}
                  >
                    {statistics?.avgConfidence}%
                  </Typography>
                </Box>
                <Box
                  display="flex"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Typography variant="body2" color="text.secondary">
                    Avg Potential Return:
                  </Typography>
                  <Typography
                    variant="body1"
                    fontWeight={700}
                    sx={{
                      color: theme.palette.warning.light,
                    }}
                  >
                    €{statistics?.avgReturn}
                  </Typography>
                </Box>
                <Box
                  display="flex"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Typography variant="body2" color="text.secondary">
                    Highest Return:
                  </Typography>
                  <Typography
                    variant="body1"
                    fontWeight={700}
                    sx={{
                      color: theme.palette.primary.light,
                    }}
                  >
                    €{statistics?.highestReturn}
                  </Typography>
                </Box>
              </Stack>
            </StickySidebar>
          </Grid>
        </Grid>
      )}

      {/* Slip Detail Modal */}
      {selectedSlip && (
        <SlipDetailModal
          open={detailModalOpen}
          onClose={() => setDetailModalOpen(false)}
          slip={selectedSlip}
        />
      )}
    </Container>
  );
};

export default GeneratedSlips;
